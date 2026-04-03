<?php
/**
 * PAGE : PageSpeed Insights (avec bouton de lancement)
 */

$hasData = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM page_pagespeed WHERE crawl_id = :cid");
    $stmt->execute([':cid' => $crawlId]);
    $hasData = $stmt->fetchColumn() > 0;
} catch (Exception $e) {}

if (!$hasData) {
    ?>
    <h1 class="page-title">PageSpeed Insights</h1>
    <script src="/scouter-enricher/assets/enricher.js"></script>
    <div id="enricher-launch"></div>
    <script>
    EnricherUI.renderLaunchButton('enricher-launch', {
        action: 'pagespeed',
        crawlId: <?= $crawlId ?>,
        label: 'Analyse PageSpeed',
        icon: 'speed',
        description: 'Analyse les 10 pages les plus importantes via l\'API Google PageSpeed Insights. Durée : ~3 minutes.'
    });
    </script>
    <?php
    return;
}

// Données
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_pages,
        ROUND(AVG(score_performance)) as avg_perf,
        ROUND(AVG(score_accessibility)) as avg_access,
        ROUND(AVG(score_best_practices)) as avg_bp,
        ROUND(AVG(score_seo)) as avg_seo,
        ROUND(AVG(fcp)::numeric, 2) as avg_fcp,
        ROUND(AVG(lcp)::numeric, 2) as avg_lcp,
        ROUND(AVG(tbt)::numeric, 0) as avg_tbt,
        ROUND(AVG(cls)::numeric, 4) as avg_cls,
        ROUND(AVG(speed_index)::numeric, 2) as avg_si
    FROM page_pagespeed WHERE crawl_id = :cid
");
$stmt->execute([':cid' => $crawlId]);
$kpis = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT CASE 
        WHEN score_performance >= 90 THEN 'Bon (90-100)'
        WHEN score_performance >= 50 THEN 'Moyen (50-89)'
        ELSE 'Faible (0-49)'
    END as range, COUNT(*) as count
    FROM page_pagespeed WHERE crawl_id = :cid GROUP BY range
");
$stmt->execute([':cid' => $crawlId]);
$perfDist = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT CASE 
        WHEN lcp <= 2.5 THEN 'Bon (≤2.5s)'
        WHEN lcp <= 4.0 THEN 'Moyen (2.5-4s)'
        ELSE 'Faible (>4s)'
    END as range, COUNT(*) as count
    FROM page_pagespeed WHERE crawl_id = :cid AND lcp IS NOT NULL GROUP BY range
");
$stmt->execute([':cid' => $crawlId]);
$lcpDist = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT url, score_performance, score_accessibility, score_best_practices, score_seo,
        fcp, lcp, tbt, cls, speed_index, ttfb
    FROM page_pagespeed WHERE crawl_id = :cid ORDER BY score_performance ASC
");
$stmt->execute([':cid' => $crawlId]);
$allPages = $stmt->fetchAll();

// Opportunités agrégées
$stmt = $pdo->prepare("SELECT opportunities FROM page_pagespeed WHERE crawl_id = :cid AND opportunities != '[]'");
$stmt->execute([':cid' => $crawlId]);
$allOpps = $stmt->fetchAll();
$oppAggregated = [];
foreach ($allOpps as $row) {
    $opps = json_decode($row->opportunities, true) ?: [];
    foreach ($opps as $opp) {
        $id = $opp['id'];
        if (!isset($oppAggregated[$id])) { $oppAggregated[$id] = ['title' => $opp['title'], 'total_savings' => 0, 'pages' => 0]; }
        $oppAggregated[$id]['total_savings'] += $opp['savings_ms'];
        $oppAggregated[$id]['pages']++;
    }
}
uasort($oppAggregated, fn($a, $b) => $b['total_savings'] - $a['total_savings']);
$oppAggregated = array_slice($oppAggregated, 0, 15, true);

?>
<h1 class="page-title">PageSpeed Insights</h1>
<p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
    Analyse mobile de <?= $kpis->total_pages ?> pages via l'API Google PageSpeed Insights
</p>
<div style="display: flex; flex-direction: column; gap: 1.5rem;">
    <div class="scorecards">
        <?php
        $perfColor = $kpis->avg_perf >= 90 ? 'success' : ($kpis->avg_perf >= 50 ? 'warning' : 'error');
        Component::card(['color' => $perfColor, 'icon' => 'speed', 'title' => 'Performance',
            'value' => $kpis->avg_perf . '/100', 'desc' => 'Score moyen mobile']);
        Component::card(['color' => 'info', 'icon' => 'accessibility', 'title' => 'Accessibilité',
            'value' => $kpis->avg_access . '/100', 'desc' => 'Score moyen']);
        Component::card(['color' => 'color1', 'icon' => 'verified', 'title' => 'Bonnes pratiques',
            'value' => $kpis->avg_bp . '/100', 'desc' => 'Score moyen']);
        Component::card(['color' => 'success', 'icon' => 'search', 'title' => 'SEO',
            'value' => $kpis->avg_seo . '/100', 'desc' => 'Score moyen']);
        ?>
    </div>
    <div class="scorecards">
        <?php
        $fcpColor = $kpis->avg_fcp <= 1.8 ? 'success' : ($kpis->avg_fcp <= 3 ? 'warning' : 'error');
        $lcpColor = $kpis->avg_lcp <= 2.5 ? 'success' : ($kpis->avg_lcp <= 4 ? 'warning' : 'error');
        $tbtColor = $kpis->avg_tbt <= 200 ? 'success' : ($kpis->avg_tbt <= 600 ? 'warning' : 'error');
        $clsColor = $kpis->avg_cls <= 0.1 ? 'success' : ($kpis->avg_cls <= 0.25 ? 'warning' : 'error');
        Component::card(['color' => $fcpColor, 'icon' => 'brush', 'title' => 'FCP',
            'value' => $kpis->avg_fcp . 's', 'desc' => 'First Contentful Paint']);
        Component::card(['color' => $lcpColor, 'icon' => 'photo_size_select_large', 'title' => 'LCP',
            'value' => $kpis->avg_lcp . 's', 'desc' => 'Largest Contentful Paint']);
        Component::card(['color' => $tbtColor, 'icon' => 'block', 'title' => 'TBT',
            'value' => $kpis->avg_tbt . 'ms', 'desc' => 'Total Blocking Time']);
        Component::card(['color' => $clsColor, 'icon' => 'swap_vert', 'title' => 'CLS',
            'value' => $kpis->avg_cls, 'desc' => 'Cumulative Layout Shift']);
        ?>
    </div>
    <div class="charts-grid">
        <?php
        $perfColors = ['Bon (90-100)' => '#34a853', 'Moyen (50-89)' => '#fbbc05', 'Faible (0-49)' => '#ea4335'];
        $perfDonut = [];
        foreach ($perfDist as $p) { $perfDonut[] = ['name' => $p->range, 'y' => (int)$p->count, 'color' => $perfColors[$p->range] ?? '#666']; }
        Component::chart(['type' => 'donut', 'title' => 'Distribution scores performance',
            'subtitle' => 'Répartition par niveau',
            'series' => [['name' => 'Pages', 'data' => $perfDonut]],
            'height' => 350, 'legendPosition' => 'bottom']);
        $lcpColors = ['Bon (≤2.5s)' => '#34a853', 'Moyen (2.5-4s)' => '#fbbc05', 'Faible (>4s)' => '#ea4335'];
        $lcpDonut = [];
        foreach ($lcpDist as $l) { $lcpDonut[] = ['name' => $l->range, 'y' => (int)$l->count, 'color' => $lcpColors[$l->range] ?? '#666']; }
        Component::chart(['type' => 'donut', 'title' => 'Distribution LCP',
            'subtitle' => 'Largest Contentful Paint par tranche',
            'series' => [['name' => 'Pages', 'data' => $lcpDonut]],
            'height' => 350, 'legendPosition' => 'bottom']);
        ?>
    </div>
    <?php
    $pageTable = [];
    foreach ($allPages as $p) {
        $pageTable[] = ['url' => parse_url($p->url, PHP_URL_PATH) ?: $p->url, 'perf' => $p->score_performance . '/100',
            'fcp' => $p->fcp . 's', 'lcp' => $p->lcp . 's', 'tbt' => round($p->tbt) . 'ms', 'cls' => $p->cls, 'si' => $p->speed_index . 's'];
    }
    Component::simpleTable(['title' => 'Détail par page', 'subtitle' => 'Du score le plus faible au plus élevé',
        'columns' => [['key' => 'url', 'label' => 'Page', 'type' => 'bold'], ['key' => 'perf', 'label' => 'Perf.', 'type' => 'default'],
            ['key' => 'fcp', 'label' => 'FCP', 'type' => 'default'], ['key' => 'lcp', 'label' => 'LCP', 'type' => 'default'],
            ['key' => 'tbt', 'label' => 'TBT', 'type' => 'default'], ['key' => 'cls', 'label' => 'CLS', 'type' => 'default'],
            ['key' => 'si', 'label' => 'Speed Index', 'type' => 'default']], 'data' => $pageTable]);
    if (!empty($oppAggregated)):
        $oppTable = [];
        foreach ($oppAggregated as $opp) {
            $oppTable[] = ['title' => $opp['title'], 'savings' => round($opp['total_savings'] / 1000, 1) . 's', 'pages' => $opp['pages'] . ' pages'];
        }
        Component::simpleTable(['title' => 'Opportunités d\'amélioration', 'subtitle' => 'Recommandations agrégées',
            'columns' => [['key' => 'title', 'label' => 'Recommandation', 'type' => 'bold'],
                ['key' => 'savings', 'label' => 'Gain potentiel', 'type' => 'default'],
                ['key' => 'pages', 'label' => 'Pages concernées', 'type' => 'default']], 'data' => $oppTable]);
    endif;
    ?>
</div>
