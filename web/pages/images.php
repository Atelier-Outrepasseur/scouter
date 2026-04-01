<?php
/**
 * ============================================================================
 * PAGE : Analyse des images
 * ============================================================================
 * Affiche les données extraites par scouter-enricher
 */

$enrichmentExists = false;
try {
    $stmt = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'page_images' LIMIT 1");
    $enrichmentExists = $stmt->rowCount() > 0;
} catch (Exception $e) {}

if (!$enrichmentExists) {
    ?>
    <h1 class="page-title"><?= __('sidebar.images') ?? 'Images' ?></h1>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <h2 style="margin-bottom: 1rem; color: var(--text-primary);">Enrichissement non lancé</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
            Lancez le script d'enrichissement pour analyser les images.
        </p>
        <code style="display: block; background: var(--bg-secondary); padding: 1rem; border-radius: 8px; color: var(--text-secondary); font-size: 0.85rem;">
            php scouter-enricher/enrich.php <?= $crawlId ?>
        </code>
    </div>
    <?php
    return;
}

// Vérifier si des données existent pour ce crawl
$stmt = $pdo->prepare("SELECT COUNT(*) FROM page_enrichment WHERE crawl_id = :cid");
$stmt->execute([':cid' => $crawlId]);
$hasData = $stmt->fetchColumn() > 0;

if (!$hasData) {
    ?>
    <h1 class="page-title">Images</h1>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <h2 style="margin-bottom: 1rem; color: var(--text-primary);">Pas de données pour ce crawl</h2>
        <p style="color: var(--text-secondary);">Lancez l'enrichissement :</p>
        <code style="display: block; background: var(--bg-secondary); padding: 1rem; border-radius: 8px; color: var(--text-secondary); font-size: 0.85rem; margin-top: 1rem;">
            php scouter-enricher/enrich.php <?= $crawlId ?>
        </code>
    </div>
    <?php
    return;
}

// ============================================================================
// REQUÊTES
// ============================================================================

// KPIs globaux
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_images,
        COUNT(CASE WHEN alt = '' OR alt IS NULL THEN 1 END) as missing_alt,
        COUNT(CASE WHEN is_lazy = true THEN 1 END) as lazy_count,
        COUNT(DISTINCT page_id) as pages_with_images
    FROM page_images WHERE crawl_id = :cid
");
$stmt->execute([':cid' => $crawlId]);
$kpis = $stmt->fetch();

// Distribution par format
$stmt = $pdo->prepare("
    SELECT COALESCE(format, 'Inconnu') as format, COUNT(*) as count
    FROM page_images WHERE crawl_id = :cid
    GROUP BY format ORDER BY count DESC
");
$stmt->execute([':cid' => $crawlId]);
$formatDist = $stmt->fetchAll();

// Pages avec le plus d'images sans alt
$stmt = $pdo->prepare("
    SELECT p.url, 
        COUNT(*) as total_images,
        COUNT(CASE WHEN pi.alt = '' OR pi.alt IS NULL THEN 1 END) as missing_alt
    FROM page_images pi
    JOIN pages p ON p.crawl_id = pi.crawl_id AND p.id = pi.page_id
    WHERE pi.crawl_id = :cid
    GROUP BY p.url
    HAVING COUNT(CASE WHEN pi.alt = '' OR pi.alt IS NULL THEN 1 END) > 0
    ORDER BY missing_alt DESC
    LIMIT 50
");
$stmt->execute([':cid' => $crawlId]);
$pagesNoAlt = $stmt->fetchAll();

// Images les plus utilisées (sur plusieurs pages)
$stmt = $pdo->prepare("
    SELECT src, format, alt, COUNT(DISTINCT page_id) as occurrences
    FROM page_images WHERE crawl_id = :cid
    GROUP BY src, format, alt
    HAVING COUNT(DISTINCT page_id) > 1
    ORDER BY occurrences DESC
    LIMIT 30
");
$stmt->execute([':cid' => $crawlId]);
$topImages = $stmt->fetchAll();

// ============================================================================
// AFFICHAGE
// ============================================================================
?>

<h1 class="page-title">Analyse des images</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- KPIs -->
    <div class="scorecards">
        <?php
        $altPct = $kpis->total_images > 0 ? round(($kpis->missing_alt / $kpis->total_images) * 100, 1) : 0;
        $lazyPct = $kpis->total_images > 0 ? round(($kpis->lazy_count / $kpis->total_images) * 100, 1) : 0;

        Component::card(['color' => 'info', 'icon' => 'image', 'title' => 'Images totales',
            'value' => number_format($kpis->total_images), 'desc' => "Sur {$kpis->pages_with_images} pages"]);
        Component::card(['color' => 'error', 'icon' => 'hide_image', 'title' => 'Sans attribut alt',
            'value' => number_format($kpis->missing_alt), 'desc' => "{$altPct}% des images"]);
        Component::card(['color' => 'success', 'icon' => 'speed', 'title' => 'Lazy loading',
            'value' => number_format($kpis->lazy_count), 'desc' => "{$lazyPct}% des images"]);
        Component::card(['color' => 'warning', 'icon' => 'web', 'title' => 'Pages avec images',
            'value' => number_format($kpis->pages_with_images), 'desc' => 'Pages contenant des images']);
        ?>
    </div>

    <!-- Graphiques -->
    <div class="charts-grid">
        <?php
        // Format distribution
        $formatDonut = [];
        $fmtColors = ['JPG' => '#ea4335', 'PNG' => '#4285f4', 'WEBP' => '#34a853', 'SVG' => '#fbbc05', 'GIF' => '#9e9e9e', 'AVIF' => '#ab47bc'];
        foreach ($formatDist as $f) {
            $formatDonut[] = [
                'name' => $f->format . ' (' . $f->count . ')',
                'y' => (int)$f->count,
                'color' => $fmtColors[$f->format] ?? '#666'
            ];
        }
        Component::chart([
            'type' => 'donut', 'title' => 'Distribution par format',
            'subtitle' => 'Formats des images détectées',
            'series' => [['name' => 'Images', 'data' => $formatDonut]],
            'height' => 350, 'legendPosition' => 'bottom'
        ]);

        // Alt vs pas alt (donut)
        $altDonut = [
            ['name' => 'Avec alt (' . ($kpis->total_images - $kpis->missing_alt) . ')', 'y' => (int)($kpis->total_images - $kpis->missing_alt), 'color' => '#34a853'],
            ['name' => 'Sans alt (' . $kpis->missing_alt . ')', 'y' => (int)$kpis->missing_alt, 'color' => '#ea4335'],
        ];
        Component::chart([
            'type' => 'donut', 'title' => 'Attribut alt',
            'subtitle' => 'Images avec vs sans attribut alt',
            'series' => [['name' => 'Images', 'data' => $altDonut]],
            'height' => 350, 'legendPosition' => 'bottom'
        ]);
        ?>
    </div>

    <!-- Pages avec images sans alt -->
    <?php if (!empty($pagesNoAlt)):
        $noAltTable = [];
        foreach ($pagesNoAlt as $row) {
            $noAltTable[] = [
                'page' => parse_url($row->url, PHP_URL_PATH) ?: $row->url,
                'total' => $row->total_images,
                'missing' => $row->missing_alt,
                'pct' => round(($row->missing_alt / $row->total_images) * 100) . '%'
            ];
        }
        Component::simpleTable([
            'title' => 'Pages avec images sans alt',
            'subtitle' => count($pagesNoAlt) . ' pages avec des images sans attribut alt',
            'columns' => [
                ['key' => 'page', 'label' => 'Page', 'type' => 'bold'],
                ['key' => 'total', 'label' => 'Total images', 'type' => 'default'],
                ['key' => 'missing', 'label' => 'Sans alt', 'type' => 'default'],
                ['key' => 'pct', 'label' => '% sans alt', 'type' => 'default'],
            ],
            'data' => $noAltTable
        ]);
    endif; ?>

    <!-- Images les plus utilisées -->
    <?php if (!empty($topImages)):
        $topImgTable = [];
        foreach ($topImages as $img) {
            $src = $img->src;
            $shortSrc = strlen($src) > 60 ? '...' . substr($src, -57) : $src;
            $topImgTable[] = [
                'src' => $shortSrc,
                'format' => $img->format ?? '-',
                'alt' => mb_substr($img->alt ?? '', 0, 40) ?: '(vide)',
                'occurrences' => $img->occurrences
            ];
        }
        Component::simpleTable([
            'title' => 'Images les plus réutilisées',
            'subtitle' => 'Images présentes sur plusieurs pages',
            'columns' => [
                ['key' => 'src', 'label' => 'Image', 'type' => 'bold'],
                ['key' => 'format', 'label' => 'Format', 'type' => 'default'],
                ['key' => 'alt', 'label' => 'Alt', 'type' => 'default'],
                ['key' => 'occurrences', 'label' => 'Pages', 'type' => 'default'],
            ],
            'data' => $topImgTable
        ]);
    endif; ?>

</div>
