<?php
/**
 * ============================================================================
 * PAGE : Analyse Hreflang
 * ============================================================================
 */

$enrichmentExists = false;
try {
    $stmt = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'page_hreflang' LIMIT 1");
    $enrichmentExists = $stmt->rowCount() > 0;
} catch (Exception $e) {}

if (!$enrichmentExists) {
    ?>
    <h1 class="page-title">Hreflang</h1>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <h2 style="margin-bottom: 1rem; color: var(--text-primary);">Enrichissement non lancé</h2>
        <p style="color: var(--text-secondary);">Lancez le script d'enrichissement pour analyser les hreflang.</p>
        <code style="display: block; background: var(--bg-secondary); padding: 1rem; border-radius: 8px; color: var(--text-secondary); font-size: 0.85rem; margin-top: 1rem;">
            php scouter-enricher/enrich.php <?= $crawlId ?>
        </code>
    </div>
    <?php
    return;
}

// Vérifier données
$stmt = $pdo->prepare("SELECT COUNT(*) FROM page_hreflang WHERE crawl_id = :cid");
$stmt->execute([':cid' => $crawlId]);
$totalHreflang = $stmt->fetchColumn();

// Pages indexables totales
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true");
$stmt->execute([':cid' => $crawlId]);
$totalCompliant = (int)$stmt->fetchColumn();

// Pages avec hreflang
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT page_id) FROM page_hreflang WHERE crawl_id = :cid");
$stmt->execute([':cid' => $crawlId]);
$pagesWithHreflang = (int)$stmt->fetchColumn();

// ============================================================================
// REQUÊTES
// ============================================================================

// Langues détectées
$stmt = $pdo->prepare("
    SELECT lang, COUNT(*) as count, COUNT(DISTINCT page_id) as pages
    FROM page_hreflang WHERE crawl_id = :cid
    GROUP BY lang ORDER BY count DESC
");
$stmt->execute([':cid' => $crawlId]);
$langDist = $stmt->fetchAll();

// Hreflangs invalides
$stmt = $pdo->prepare("
    SELECT ph.lang, ph.href, p.url as page_url
    FROM page_hreflang ph
    JOIN pages p ON p.crawl_id = ph.crawl_id AND p.id = ph.page_id
    WHERE ph.crawl_id = :cid AND ph.is_valid = false
    LIMIT 50
");
$stmt->execute([':cid' => $crawlId]);
$invalidHreflangs = $stmt->fetchAll();

// Pages sans self-referencing hreflang
$stmt = $pdo->prepare("
    SELECT p.url, COUNT(ph.id) as hreflang_count
    FROM page_hreflang ph
    JOIN pages p ON p.crawl_id = ph.crawl_id AND p.id = ph.page_id
    WHERE ph.crawl_id = :cid
    GROUP BY p.url, ph.page_id
    HAVING SUM(CASE WHEN ph.is_self = true THEN 1 ELSE 0 END) = 0
    ORDER BY hreflang_count DESC
    LIMIT 50
");
$stmt->execute([':cid' => $crawlId]);
$noSelfRef = $stmt->fetchAll();

// ============================================================================
// AFFICHAGE
// ============================================================================
?>

<h1 class="page-title">Analyse Hreflang</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- KPIs -->
    <div class="scorecards">
        <?php
        $coveragePct = $totalCompliant > 0 ? round(($pagesWithHreflang / $totalCompliant) * 100, 1) : 0;
        $langCount = count($langDist);

        Component::card(['color' => 'info', 'icon' => 'translate', 'title' => 'Balises hreflang',
            'value' => number_format($totalHreflang), 'desc' => 'Total des balises détectées']);
        Component::card(['color' => 'success', 'icon' => 'language', 'title' => 'Pages avec hreflang',
            'value' => number_format($pagesWithHreflang), 'desc' => "{$coveragePct}% des pages indexables"]);
        Component::card(['color' => 'warning', 'icon' => 'public', 'title' => 'Langues détectées',
            'value' => $langCount, 'desc' => 'Langues/régions différentes']);
        Component::card(['color' => 'error', 'icon' => 'error_outline', 'title' => 'Hreflang invalides',
            'value' => count($invalidHreflangs), 'desc' => 'URLs mal formées']);
        ?>
    </div>

    <?php if ($totalHreflang == 0): ?>
    <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
        <p>Aucune balise hreflang détectée sur ce site. C'est normal pour les sites monolingues.</p>
    </div>
    <?php else: ?>

    <!-- Graphiques -->
    <div class="charts-grid">
        <?php
        // Distribution par langue
        $langDonut = [];
        foreach ($langDist as $l) {
            $langDonut[] = ['name' => $l->lang . ' (' . $l->pages . ' pages)', 'y' => (int)$l->count];
        }
        Component::chart([
            'type' => 'donut', 'title' => 'Distribution par langue',
            'subtitle' => 'Nombre de balises hreflang par langue',
            'series' => [['name' => 'Hreflang', 'data' => $langDonut]],
            'height' => 350, 'legendPosition' => 'bottom'
        ]);

        // Couverture (bar)
        $coverageData = [
            ['name' => 'Avec hreflang', 'y' => $pagesWithHreflang, 'color' => '#34a853'],
            ['name' => 'Sans hreflang', 'y' => $totalCompliant - $pagesWithHreflang, 'color' => '#ea4335'],
        ];
        Component::chart([
            'type' => 'donut', 'title' => 'Couverture hreflang',
            'subtitle' => 'Pages indexables avec vs sans hreflang',
            'series' => [['name' => 'Pages', 'data' => $coverageData]],
            'height' => 350, 'legendPosition' => 'bottom'
        ]);
        ?>
    </div>

    <!-- Pages sans self-referencing -->
    <?php if (!empty($noSelfRef)):
        $noSelfTable = [];
        foreach ($noSelfRef as $row) {
            $noSelfTable[] = [
                'page' => parse_url($row->url, PHP_URL_PATH) ?: $row->url,
                'count' => $row->hreflang_count,
            ];
        }
        Component::simpleTable([
            'title' => 'Pages sans auto-référence hreflang',
            'subtitle' => 'Pages ayant des hreflang mais pas de balise pointant vers elles-mêmes',
            'columns' => [
                ['key' => 'page', 'label' => 'Page', 'type' => 'bold'],
                ['key' => 'count', 'label' => 'Hreflang', 'type' => 'default'],
            ],
            'data' => $noSelfTable
        ]);
    endif; ?>

    <!-- Hreflangs invalides -->
    <?php if (!empty($invalidHreflangs)):
        $invalidTable = [];
        foreach ($invalidHreflangs as $row) {
            $invalidTable[] = [
                'page' => parse_url($row->page_url, PHP_URL_PATH) ?: $row->page_url,
                'lang' => $row->lang,
                'href' => mb_substr($row->href, 0, 60),
            ];
        }
        Component::simpleTable([
            'title' => 'Hreflang invalides',
            'subtitle' => 'Balises hreflang avec des URLs mal formées',
            'columns' => [
                ['key' => 'page', 'label' => 'Page', 'type' => 'bold'],
                ['key' => 'lang', 'label' => 'Langue', 'type' => 'default'],
                ['key' => 'href', 'label' => 'URL cible', 'type' => 'default'],
            ],
            'data' => $invalidTable
        ]);
    endif; ?>

    <?php endif; // fin totalHreflang > 0 ?>

</div>
