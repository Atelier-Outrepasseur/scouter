<?php
/**
 * ============================================================================
 * PAGE : Sitemap & Robots.txt
 * ============================================================================
 */

$hasData = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sitemap_info WHERE crawl_id = :cid");
    $stmt->execute([':cid' => $crawlId]);
    $hasData = $stmt->fetchColumn() > 0;
} catch (Exception $e) {}

if (!$hasData) {
    ?>
    <h1 class="page-title">Sitemap & Robots.txt</h1>
    <script src="/scouter-enricher/assets/enricher.js"></script>
    <div id="enricher-launch"></div>
    <script>
    EnricherUI.renderLaunchButton('enricher-launch', {
        action: 'sitemap',
        crawlId: <?= $crawlId ?>,
        label: 'Analyser le Sitemap',
        icon: 'map',
        description: 'Télécharge le robots.txt et parse le sitemap.xml pour croiser avec le crawl.'
    });
    </script>
    <?php
    return;
}

// ============================================================================
// DONNÉES
// ============================================================================

$stmt = $pdo->prepare("SELECT * FROM sitemap_info WHERE crawl_id = :cid");
$stmt->execute([':cid' => $crawlId]);
$info = $stmt->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM sitemap_urls WHERE crawl_id = :cid");
$stmt->execute([':cid' => $crawlId]);
$totalSitemapUrls = (int)$stmt->fetchColumn();

// Pages internes crawlées
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND external = false AND crawled = true");
$stmt->execute([':cid' => $crawlId]);
$totalCrawled = (int)$stmt->fetchColumn();

// Pages indexables crawlées
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true");
$stmt->execute([':cid' => $crawlId]);
$totalCompliant = (int)$stmt->fetchColumn();

// ─── SITEMAP VS STRUCTURE ───
// Pages dans les deux
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM sitemap_urls s
    JOIN pages p ON p.crawl_id = s.crawl_id AND p.url = s.url
    WHERE s.crawl_id = :cid AND p.external = false
");
$stmt->execute([':cid' => $crawlId]);
$inBoth = (int)$stmt->fetchColumn();

// Pages dans sitemap seulement (pas dans crawl)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM sitemap_urls s
    LEFT JOIN pages p ON p.crawl_id = s.crawl_id AND p.url = s.url
    WHERE s.crawl_id = :cid AND p.id IS NULL
");
$stmt->execute([':cid' => $crawlId]);
$inSitemapOnly = (int)$stmt->fetchColumn();

// Pages dans crawl seulement (pas dans sitemap)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM pages p
    LEFT JOIN sitemap_urls s ON s.crawl_id = p.crawl_id AND s.url = p.url
    WHERE p.crawl_id = :cid AND p.external = false AND p.crawled = true AND p.compliant = true
    AND s.id IS NULL
");
$stmt->execute([':cid' => $crawlId]);
$inCrawlOnly = (int)$stmt->fetchColumn();

// ─── SITEMAP VS INDEXABILITÉ ───
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM sitemap_urls s
    JOIN pages p ON p.crawl_id = s.crawl_id AND p.url = s.url
    WHERE s.crawl_id = :cid AND p.compliant = true
");
$stmt->execute([':cid' => $crawlId]);
$sitemapIndexable = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM sitemap_urls s
    JOIN pages p ON p.crawl_id = s.crawl_id AND p.url = s.url
    WHERE s.crawl_id = :cid AND p.compliant = false
");
$stmt->execute([':cid' => $crawlId]);
$sitemapNotIndexable = (int)$stmt->fetchColumn();

// Détail : pages sitemap non indexables
$stmt = $pdo->prepare("
    SELECT s.url, p.code, p.noindex, p.canonical,
        CASE 
            WHEN p.code != 200 THEN 'Code HTTP ' || p.code
            WHEN p.noindex = true THEN 'noindex'
            WHEN p.canonical = false THEN 'canonical différent'
            ELSE 'Autre'
        END as raison
    FROM sitemap_urls s
    JOIN pages p ON p.crawl_id = s.crawl_id AND p.url = s.url
    WHERE s.crawl_id = :cid AND p.compliant = false
    ORDER BY p.code DESC
    LIMIT 100
");
$stmt->execute([':cid' => $crawlId]);
$sitemapNotIndexableDetail = $stmt->fetchAll();

// Détail : pages sitemap seulement (pas dans crawl)
$stmt = $pdo->prepare("
    SELECT s.url, s.lastmod
    FROM sitemap_urls s
    LEFT JOIN pages p ON p.crawl_id = s.crawl_id AND p.url = s.url
    WHERE s.crawl_id = :cid AND p.id IS NULL
    ORDER BY s.lastmod DESC NULLS LAST
    LIMIT 100
");
$stmt->execute([':cid' => $crawlId]);
$sitemapOnlyDetail = $stmt->fetchAll();

// Détail : pages crawlées indexables pas dans sitemap
$stmt = $pdo->prepare("
    SELECT p.url, p.title, p.inlinks
    FROM pages p
    LEFT JOIN sitemap_urls s ON s.crawl_id = p.crawl_id AND s.url = p.url
    WHERE p.crawl_id = :cid AND p.external = false AND p.compliant = true AND s.id IS NULL
    ORDER BY p.inlinks DESC
    LIMIT 100
");
$stmt->execute([':cid' => $crawlId]);
$crawlOnlyDetail = $stmt->fetchAll();

// ============================================================================
// AFFICHAGE
// ============================================================================
?>

<h1 class="page-title">Sitemap & Robots.txt</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- KPIs -->
    <div class="scorecards">
        <?php
        Component::card(['color' => $info->robots_found ? 'success' : 'error', 'icon' => 'smart_toy', 'title' => 'Robots.txt',
            'value' => $info->robots_found ? 'Trouvé' : 'Absent', 'desc' => $info->robots_found ? 'Fichier robots.txt détecté' : 'Aucun robots.txt']);
        Component::card(['color' => $info->sitemap_found ? 'success' : 'error', 'icon' => 'map', 'title' => 'Sitemap',
            'value' => $info->sitemap_found ? 'Trouvé' : 'Absent', 'desc' => $info->sitemaps_parsed . ' sitemap(s) parsé(s)']);
        Component::card(['color' => 'info', 'icon' => 'link', 'title' => 'URLs dans sitemap',
            'value' => number_format($totalSitemapUrls), 'desc' => 'URLs listées dans le sitemap']);
        Component::card(['color' => 'warning', 'icon' => 'compare_arrows', 'title' => 'Pages croisées',
            'value' => number_format($inBoth), 'desc' => 'Dans sitemap ET dans le crawl']);
        ?>
    </div>

    <!-- Graphiques Sitemap vs Structure / Indexabilité -->
    <div class="charts-grid">
        <?php
        // Sitemap vs Structure
        $structDonut = [
            ['name' => 'Dans les deux (' . $inBoth . ')', 'y' => $inBoth, 'color' => '#34a853'],
            ['name' => 'Sitemap seulement (' . $inSitemapOnly . ')', 'y' => max($inSitemapOnly, 0), 'color' => '#ab47bc'],
            ['name' => 'Crawl seulement (' . $inCrawlOnly . ')', 'y' => max($inCrawlOnly, 0), 'color' => '#4285f4'],
        ];
        Component::chart([
            'type' => 'donut', 'title' => 'Sitemap vs Structure',
            'subtitle' => 'Comparaison des URLs sitemap et crawl',
            'series' => [['name' => 'URLs', 'data' => $structDonut]],
            'height' => 350, 'legendPosition' => 'bottom'
        ]);

        // Sitemap vs Indexabilité
        $indexDonut = [
            ['name' => 'Indexable (' . $sitemapIndexable . ')', 'y' => max($sitemapIndexable, 0), 'color' => '#34a853'],
            ['name' => 'Non indexable (' . $sitemapNotIndexable . ')', 'y' => max($sitemapNotIndexable, 0), 'color' => '#ea4335'],
        ];
        Component::chart([
            'type' => 'donut', 'title' => 'Sitemap vs Indexabilité',
            'subtitle' => 'Pages du sitemap indexables vs non indexables',
            'series' => [['name' => 'Pages', 'data' => $indexDonut]],
            'height' => 350, 'legendPosition' => 'bottom'
        ]);
        ?>
    </div>

    <!-- Pages dans sitemap mais non indexables -->
    <?php if (!empty($sitemapNotIndexableDetail)):
        $table = [];
        foreach ($sitemapNotIndexableDetail as $row) {
            $table[] = [
                'url' => parse_url($row->url, PHP_URL_PATH) ?: $row->url,
                'code' => $row->code,
                'raison' => $row->raison,
            ];
        }
        Component::simpleTable([
            'title' => 'Pages sitemap non indexables (' . $sitemapNotIndexable . ')',
            'subtitle' => 'Ces pages sont dans votre sitemap mais ne sont pas indexables — à retirer du sitemap ou corriger',
            'columns' => [
                ['key' => 'url', 'label' => 'URL', 'type' => 'bold'],
                ['key' => 'code', 'label' => 'Code', 'type' => 'default'],
                ['key' => 'raison', 'label' => 'Raison', 'type' => 'default'],
            ],
            'data' => $table
        ]);
    endif; ?>

    <!-- Pages dans sitemap seulement (pas dans crawl) -->
    <?php if (!empty($sitemapOnlyDetail)):
        $table = [];
        foreach ($sitemapOnlyDetail as $row) {
            $table[] = [
                'url' => parse_url($row->url, PHP_URL_PATH) ?: $row->url,
                'lastmod' => $row->lastmod ? date('d/m/Y', strtotime($row->lastmod)) : '-',
            ];
        }
        Component::simpleTable([
            'title' => 'Pages sitemap hors crawl (' . $inSitemapOnly . ')',
            'subtitle' => 'Ces URLs sont dans le sitemap mais n\'ont pas été trouvées par le crawl — orphelines ou inaccessibles',
            'columns' => [
                ['key' => 'url', 'label' => 'URL', 'type' => 'bold'],
                ['key' => 'lastmod', 'label' => 'Dernière modif', 'type' => 'default'],
            ],
            'data' => $table
        ]);
    endif; ?>

    <!-- Pages crawlées indexables pas dans sitemap -->
    <?php if (!empty($crawlOnlyDetail)):
        $table = [];
        foreach ($crawlOnlyDetail as $row) {
            $table[] = [
                'url' => parse_url($row->url, PHP_URL_PATH) ?: $row->url,
                'title' => mb_substr($row->title ?? '', 0, 50),
                'inlinks' => $row->inlinks,
            ];
        }
        Component::simpleTable([
            'title' => 'Pages indexables absentes du sitemap (' . $inCrawlOnly . ')',
            'subtitle' => 'Ces pages sont indexables et dans la structure mais pas dans le sitemap — à ajouter',
            'columns' => [
                ['key' => 'url', 'label' => 'URL', 'type' => 'bold'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'default'],
                ['key' => 'inlinks', 'label' => 'Inlinks', 'type' => 'default'],
            ],
            'data' => $table
        ]);
    endif; ?>

    <!-- Robots.txt -->
    <?php if ($info->robots_found && !empty($info->robots_txt)): ?>
    <div class="chart-card">
        <div class="chart-header">
            <div>
                <h3 class="chart-title">Fichier robots.txt</h3>
                <p class="chart-subtitle">Contenu actuel du fichier</p>
            </div>
        </div>
        <div style="padding: 0 1rem 1rem;">
            <pre style="background: var(--bg-secondary); padding: 1rem; border-radius: 8px; font-size: 0.8rem; 
                        color: var(--text-primary); overflow-x: auto; white-space: pre-wrap; max-height: 400px; overflow-y: auto;"><?= htmlspecialchars($info->robots_txt) ?></pre>
        </div>
    </div>
    <?php endif; ?>

</div>
