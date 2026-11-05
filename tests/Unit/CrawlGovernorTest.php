<?php

use App\Core\CrawlGovernor;
use App\Core\FetchOutcome;

function governor(string $speed, ?int $cap = null, ?int $maxPause = 1800, array &$slept = [], array &$logs = []): CrawlGovernor
{
    $now = 0.0;
    return new CrawlGovernor(
        $speed,
        $cap,
        $maxPause,
        function () use (&$now) { return $now; },
        function (float $s) use (&$now, &$slept) { $now += $s; $slept[] = $s; },
        function (string $m) use (&$logs) { $logs[] = $m; }
    );
}

function ok(): FetchOutcome { return FetchOutcome::classify(200); }
function timeoutOutcome(): FetchOutcome { return FetchOutcome::classify(0, 28, '', 'timed out'); }

describe('CrawlGovernor', function () {

    it('MAX_CONCURRENT_CURL est un plafond : il ne peut plus augmenter une vitesse limitée', function () {
        $g = governor('fast', 30);
        expect($g->concurrency())->toBe(8);   // et non 30 comme avant
        expect($g->rate())->toBe(15.0);
        expect(governor('very_slow', 30)->concurrency())->toBe(1);
        expect(governor('slow', 2)->concurrency())->toBe(2);    // plafond plus bas : respecté
    });

    it('en mode illimité seulement, la variable fixe le parallélisme', function () {
        $g = governor('unlimited', 30);
        expect($g->concurrency())->toBe(30);
        expect($g->rate())->toBe(0.0);
    });

    it('ne ralentit pas sur des réponses normales, même des 404', function () {
        $g = governor('fast');
        for ($i = 0; $i < 50; $i++) {
            $g->record(FetchOutcome::classify($i % 5 === 0 ? 404 : 200));
        }
        expect($g->rate())->toBe(15.0);
        expect($g->shouldPause())->toBeFalse();
    });

    it('ralentit dès 3 signaux de blocage consécutifs', function () {
        $logs = [];
        $slept = [];
        $g = governor('fast', null, 1800, $slept, $logs);
        $g->record(timeoutOutcome());
        $g->record(timeoutOutcome());
        expect($g->rate())->toBe(15.0);
        $g->record(timeoutOutcome());
        expect($g->rate())->toBe(7.0);
        expect($g->concurrency())->toBe(4);
        expect($logs[0])->toContain('ralentit ou bloque');
    });

    it('ralentit quand 30 % des dernières requêtes sont bloquées, même non consécutives', function () {
        $g = governor('fast');
        $seq = [ok(), timeoutOutcome(), ok(), timeoutOutcome(), ok(), FetchOutcome::classify(429)];
        foreach ($seq as $o) {
            $g->record($o);
        }
        expect($g->rate())->toBeLessThan(15.0);
    });

    it('descend jusqu\'au plancher puis demande une pause, puis reprend au plancher', function () {
        $slept = [];
        $logs = [];
        $g = governor('fast', null, 1800, $slept, $logs);
        for ($i = 0; $i < 30 && !$g->shouldPause(); $i++) {
            $g->record(timeoutOutcome());
        }
        expect($g->rate())->toBe(1.0);
        expect($g->concurrency())->toBe(1);
        expect($g->shouldPause())->toBeTrue();

        $d = $g->pause();
        expect($d)->toBe(60.0);
        expect(array_sum($slept))->toBe(60.0);
        expect($g->shouldPause())->toBeFalse();
        expect($g->rate())->toBe(1.0);
        expect(implode("\n", $logs))->toContain('pause de 60 s');
        expect(implode("\n", $logs))->toContain('Reprise');
    });

    it('allonge les pauses si le site bloque encore, et les remet à 60 s quand il répond de nouveau', function () {
        $slept = [];
        $g = governor('very_slow', null, 100000, $slept);
        $block = function () use ($g) { for ($i = 0; $i < 3; $i++) { $g->record(timeoutOutcome()); } };
        $block();
        expect($g->pause())->toBe(60.0);
        $block();
        expect($g->pause())->toBe(120.0);
        $block();
        expect($g->pause())->toBe(240.0);
        // Le site répond de nouveau normalement
        for ($i = 0; $i < 5; $i++) {
            $g->record(ok());
        }
        $block();
        expect($g->pause())->toBe(60.0);
    });

    it('réaccélère progressivement, sans dépasser ni la vitesse choisie ni le débit qui a déclenché le blocage', function () {
        $g = governor('slow'); // 5 req/s, 3 connexions
        for ($i = 0; $i < 3; $i++) {
            $g->record(timeoutOutcome());
        }
        expect($g->rate())->toBe(2.0);
        for ($i = 0; $i < 200; $i++) {
            $g->record(ok());
        }
        expect($g->rate())->toBe(4.0);        // bloqué à 5 → plafond 4
        expect($g->concurrency())->toBe(3);

        $sans = governor('slow');
        for ($i = 0; $i < 200; $i++) {
            $sans->record(ok());
        }
        expect($sans->rate())->toBe(5.0);     // jamais au-delà de la vitesse choisie
    });

    it('apprend le débit qui déclenche le blocage et ne le dépasse plus', function () {
        $g = governor('fast');
        // Le site bloque au départ (15 req/s) : ralentissements jusqu'au plancher
        for ($i = 0; $i < 9; $i++) { $g->record(timeoutOutcome()); }
        expect($g->rate())->toBe(1.0);
        for ($i = 0; $i < 3; $i++) { $g->record(timeoutOutcome()); }
        $g->pause();
        // Le site répond : on remonte jusqu'à 5 req/s…
        for ($i = 0; $i < 80; $i++) { $g->record(ok()); }
        expect($g->rate())->toBe(5.0);
        // …où il bloque de nouveau : plafond appris = 4
        for ($i = 0; $i < 3; $i++) { $g->record(timeoutOutcome()); }
        for ($i = 0; $i < 400; $i++) { $g->record(ok()); }
        expect($g->rate())->toBe(4.0);
        expect($g->stats()['ceiling'])->toBe(4.0);
    });

    it('signale l\'abandon au-delà du temps de pause cumulé autorisé', function () {
        $g = governor('very_slow', null, 150);
        for ($i = 0; $i < 3; $i++) { $g->record(timeoutOutcome()); }
        $g->pause();                       // 60 s
        expect($g->shouldGiveUp())->toBeFalse();
        for ($i = 0; $i < 3; $i++) { $g->record(timeoutOutcome()); }
        $g->pause();                       // + 120 s = 180 s
        expect($g->shouldGiveUp())->toBeTrue();
    });

    it('les erreurs serveur passagères ne déclenchent pas de ralentissement', function () {
        $g = governor('fast');
        for ($i = 0; $i < 10; $i++) {
            $g->record(FetchOutcome::classify(500));
        }
        expect($g->rate())->toBe(15.0);
    });

    it('taille de lot et durée minimale respectent le débit', function () {
        $g = governor('very_slow');
        expect($g->batchSize())->toBe(1);
        expect($g->minBatchDuration(1))->toBe(1.0);
        $f = governor('fast');
        expect($f->batchSize())->toBe(15);
        expect($f->minBatchDuration(15))->toBe(1.0);
    });
});
