<?php

use App\Core\FetchOutcome;

describe('FetchOutcome : vraie réponse de page ou absence de réponse', function () {

    it('classe les vraies réponses de page comme réponses (y compris 404, 410, 403 normal)', function () {
        foreach ([200, 301, 302, 404, 410, 403, 401] as $code) {
            $o = FetchOutcome::classify($code, 0, '<html><title>Page</title></html>');
            expect($o->kind)->toBe(FetchOutcome::KIND_RESPONSE);
            expect($o->isPageResponse())->toBeTrue();
            expect($o->isBlockingSignal())->toBeFalse();
            expect($o->httpCode)->toBe($code);
        }
    });

    it('classe un délai dépassé (cURL 28) comme timeout, jamais comme erreur de page', function () {
        $o = FetchOutcome::classify(0, 28, '', 'Operation timed out after 20001 milliseconds');
        expect($o->kind)->toBe(FetchOutcome::KIND_TIMEOUT);
        expect($o->isPageResponse())->toBeFalse();
        expect($o->isBlockingSignal())->toBeTrue();
        expect($o->unverifiedStatus())->toBe('timeout');
    });

    it('classe connexion refusée, coupée ou vide comme erreur réseau', function () {
        foreach ([7, 35, 52, 56, 6] as $errno) {
            $o = FetchOutcome::classify(0, $errno, '', 'failure');
            expect($o->kind)->toBe(FetchOutcome::KIND_NETWORK);
            expect($o->isBlockingSignal())->toBeTrue();
            expect($o->unverifiedStatus())->toBe('network');
        }
    });

    it('classe un code 0 sans numéro d\'erreur comme absence de réponse', function () {
        expect(FetchOutcome::classify(0, 0)->kind)->toBe(FetchOutcome::KIND_NETWORK);
    });

    it('classe 429 et 503 comme blocage du site', function () {
        expect(FetchOutcome::classify(429)->kind)->toBe(FetchOutcome::KIND_BLOCKED);
        expect(FetchOutcome::classify(503)->kind)->toBe(FetchOutcome::KIND_BLOCKED);
        expect(FetchOutcome::classify(429)->unverifiedStatus())->toBe('blocked');
    });

    it('reconnaît une page de défi anti-robot en 403', function () {
        $cf = '<html><head><title>Attention Required! | Cloudflare</title></head><body></body></html>';
        $o = FetchOutcome::classify(403, 0, $cf);
        expect($o->kind)->toBe(FetchOutcome::KIND_BLOCKED);
        expect($o->isBlockingSignal())->toBeTrue();
        // Un 403 ordinaire reste une vraie réponse
        expect(FetchOutcome::classify(403, 0, '<title>Accès interdit</title>')->kind)->toBe(FetchOutcome::KIND_RESPONSE);
    });

    it('traite 500, 502, 504 comme erreurs passagères (réessayées, pas des signaux de blocage)', function () {
        foreach ([500, 502, 504] as $code) {
            $o = FetchOutcome::classify($code);
            expect($o->kind)->toBe(FetchOutcome::KIND_TRANSIENT);
            expect($o->isRetryable())->toBeTrue();
            expect($o->isBlockingSignal())->toBeFalse();
        }
    });

    it('garde le code reçu si cURL échoue après la réception des en-têtes', function () {
        $o = FetchOutcome::classify(200, 23, '<html>', 'write error');
        expect($o->kind)->toBe(FetchOutcome::KIND_RESPONSE);
        expect($o->httpCode)->toBe(200);
    });

    it('distingue un échec du renderer JS d\'un délai dépassé', function () {
        expect(FetchOutcome::renderer('Navigation timeout of 30000 ms exceeded')->kind)->toBe(FetchOutcome::KIND_TIMEOUT);
        $o = FetchOutcome::renderer('Target closed');
        expect($o->kind)->toBe(FetchOutcome::KIND_RENDERER);
        expect($o->isBlockingSignal())->toBeFalse();
        expect($o->unverifiedStatus())->toBe('renderer');
    });
});
