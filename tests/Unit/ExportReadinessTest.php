<?php

use App\Util\ExportReadiness;

describe('ExportReadiness (avertissement avant export)', function () {

    beforeEach(function () {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    });

    it('signale un enrichissement et un PageSpeed non lancés quand les tables sont absentes', function () {
        expect(ExportReadiness::forCrawl($this->pdo, 7))->toBe(['enrichment' => false, 'pagespeed' => false]);
    });

    it('ne compte que les analyses du crawl demandé', function () {
        $this->pdo->exec('CREATE TABLE page_enrichment (crawl_id INTEGER, page_id TEXT)');
        $this->pdo->exec('CREATE TABLE page_pagespeed (crawl_id INTEGER, page_id TEXT)');
        $this->pdo->exec("INSERT INTO page_enrichment VALUES (3, 'a'), (3, 'b')");
        $this->pdo->exec("INSERT INTO page_pagespeed VALUES (5, 'a')");

        expect(ExportReadiness::forCrawl($this->pdo, 3))->toBe(['enrichment' => true, 'pagespeed' => false]);
        expect(ExportReadiness::forCrawl($this->pdo, 5))->toBe(['enrichment' => false, 'pagespeed' => true]);
        expect(ExportReadiness::forCrawl($this->pdo, 9))->toBe(['enrichment' => false, 'pagespeed' => false]);
    });
});

describe('Page Export : listes et avertissement', function () {

    beforeEach(function () {
        $this->src = file_get_contents(__DIR__ . '/../../web/pages/export.php');
    });

    it('liste les 10 diapositives produites par scouter-google', function () {
        foreach (['Couverture', 'Synthèse', "Plan d'action", 'Codes de réponse et liens cassés', 'Indexabilité',
                  'Titres, metas et H1', 'Contenu et temps de réponse', 'Maillage et profondeur', 'Images',
                  'Méthode et limites'] as $slide) {
            expect($this->src)->toContain($slide);
        }
        // anciennes diapositives qui n'existent plus
        expect($this->src)->not->toContain('Todolist');
        expect($this->src)->not->toContain("Vue d'ensemble");
    });

    it('liste les onglets du classeur sous leur vrai nom', function () {
        foreach (['Index', 'Todo', 'Code de réponse', 'Balises Titres', 'Balises Desc', 'Balises H1', 'Nombre de mots',
                  'Temps de réponse', 'Profondeur', 'Maillage interne', 'Données structurées', 'Images', 'Hreflang',
                  'PageSpeed', 'Non vérifiées'] as $tab) {
            expect($this->src)->toContain($tab);
        }
        expect($this->src)->not->toContain('Codes de réponse, Balises');
    });

    it("prévient avant l'export quand l'enrichissement ou PageSpeed n'ont pas été lancés", function () {
        expect($this->src)->toContain('ExportReadiness::forCrawl');
        expect($this->src)->toContain('id="export-not-measured"');
        expect($this->src)->toContain('Non mesuré');
    });
});
