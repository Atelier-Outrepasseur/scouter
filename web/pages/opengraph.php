<?php
/**
 * PAGE : Open Graph & Twitter Cards (avec bouton de lancement)
 */

$hasData = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM page_opengraph WHERE crawl_id = :cid");
    $stmt->execute([':cid' => $crawlId]);
    $hasData = $stmt->fetchColumn() > 0;
} catch (Exception $e) {}

if (!$hasData) {
    ?>
    <h1 class="page-title">Open Graph & Twitter Cards</h1>
    <script src="/scouter-enricher/assets/enricher.js"></script>
    <div id="enricher-launch"></div>
    <script>
    EnricherUI.renderLaunchButton('enricher-launch', {
        action: 'enrich',
        crawlId: <?= $crawlId ?>,
        label: 'Analyse Open Graph',
        icon: 'share',
        description: 'Extrait les balises Open Graph, Twitter Cards, images et hreflang.'
    });
    </script>
    <?php
    return;
}

// Données
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true");
$stmt->execute([':cid' => $crawlId]);
$totalCompliant = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) as total,
        COUNT(CASE WHEN og_title IS NOT NULL AND og_title != '' THEN 1 END) as with_og_title,
        COUNT(CASE WHEN og_image IS NOT NULL AND og_image != '' THEN 1 END) as with_og_image,
        COUNT(CASE WHEN og_description IS NOT NULL AND og_description != '' THEN 1 END) as with_og_desc,
        COUNT(CASE WHEN twitter_card IS NOT NULL AND twitter_card != '' THEN 1 END) as with_twitter
    FROM page_opengraph WHERE crawl_id = :cid
");
$stmt->execute([':cid' => $crawlId]);
$kpis = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(og_type, ''), 'Non défini') as og_type, COUNT(*) as count
    FROM page_opengraph WHERE crawl_id = :cid GROUP BY og_type ORDER BY count DESC
");
$stmt->execute([':cid' => $crawlId]);
$typeDist = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT p.url FROM page_opengraph og
    JOIN pages p ON p.crawl_id = og.crawl_id AND p.id = og.page_id
    WHERE og.crawl_id = :cid AND p.compliant = true
        AND (og.og_title IS NULL OR og.og_title = '') AND (og.og_image IS NULL OR og.og_image = '')
    ORDER BY p.inlinks DESC LIMIT 50
");
$stmt->execute([':cid' => $crawlId]);
$pagesNoOg = $stmt->fetchAll();

?>
<h1 class="page-title">Open Graph & Twitter Cards</h1>
<div style="display: flex; flex-direction: column; gap: 1.5rem;">
    <div class="scorecards">
        <?php
        $ogPct = $totalCompliant > 0 ? round(($kpis->with_og_title / $totalCompliant) * 100, 1) : 0;
        $imgPct = $totalCompliant > 0 ? round(($kpis->with_og_image / $totalCompliant) * 100, 1) : 0;
        Component::card(['color' => 'success', 'icon' => 'share', 'title' => 'og:title',
            'value' => number_format($kpis->with_og_title), 'desc' => "{$ogPct}% des pages"]);
        Component::card(['color' => 'info', 'icon' => 'image', 'title' => 'og:image',
            'value' => number_format($kpis->with_og_image), 'desc' => "{$imgPct}% des pages"]);
        Component::card(['color' => 'warning', 'icon' => 'description', 'title' => 'og:description',
            'value' => number_format($kpis->with_og_desc), 'desc' => 'Pages avec og:description']);
        Component::card(['color' => 'color1', 'icon' => 'alternate_email', 'title' => 'Twitter Card',
            'value' => number_format($kpis->with_twitter), 'desc' => 'Pages avec twitter:card']);
        ?>
    </div>
    <div class="charts-grid">
        <?php
        $completionDonut = [
            ['name' => 'Avec og:title', 'y' => (int)$kpis->with_og_title, 'color' => '#34a853'],
            ['name' => 'Sans og:title', 'y' => (int)($totalCompliant - $kpis->with_og_title), 'color' => '#ea4335'],
        ];
        Component::chart(['type' => 'donut', 'title' => 'Couverture Open Graph',
            'subtitle' => 'Pages indexables avec og:title',
            'series' => [['name' => 'Pages', 'data' => $completionDonut]],
            'height' => 350, 'legendPosition' => 'bottom']);
        if (!empty($typeDist)) {
            $typeDonut = [];
            foreach ($typeDist as $t) { $typeDonut[] = ['name' => $t->og_type, 'y' => (int)$t->count]; }
            Component::chart(['type' => 'donut', 'title' => 'Distribution og:type',
                'subtitle' => 'Types Open Graph détectés',
                'series' => [['name' => 'Pages', 'data' => $typeDonut]],
                'height' => 350, 'legendPosition' => 'bottom']);
        }
        ?>
    </div>
    <?php if (!empty($pagesNoOg)):
        $noOgTable = [];
        foreach ($pagesNoOg as $row) { $noOgTable[] = ['page' => parse_url($row->url, PHP_URL_PATH) ?: $row->url]; }
        Component::simpleTable(['title' => 'Pages sans Open Graph (' . count($pagesNoOg) . ')',
            'subtitle' => 'Pages indexables sans og:title ni og:image',
            'columns' => [['key' => 'page', 'label' => 'Page', 'type' => 'bold']], 'data' => $noOgTable]);
    endif; ?>
</div>
