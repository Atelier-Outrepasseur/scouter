<?php
/**
 * ============================================================================
 * PAGE : Search Console (v2)
 * ============================================================================
 * - KPIs, évolution clics/impressions, distribution positions
 * - Branded vs Non-branded
 * - Mots-clés gagnés / perdus (comparaison 2 périodes)
 * - Top mots-clés, Top pages
 * - Croisement Crawl × Search Console
 */

// ============================================================================
// VÉRIFICATIONS PRÉALABLES
// ============================================================================

$googleTablesExist = false;
try {
    $checkTable = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'gsc_keywords' LIMIT 1");
    $googleTablesExist = $checkTable->rowCount() > 0;
} catch (Exception $e) {}

if (!$googleTablesExist) {
    ?>
    <h1 class="page-title">Search Console</h1>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <h2 style="margin-bottom: 1rem; color: var(--text-primary);">Service Google non connecté</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
            Le service scouter-google n'est pas encore configuré.
        </p>
        <code style="display: block; background: var(--bg-secondary); padding: 1rem; border-radius: 8px; color: var(--text-secondary); font-size: 0.85rem;">
            cd scouter-google && node src/index.js
        </code>
    </div>
    <?php
    return;
}

// Chercher la propriété GSC
$crawlDomain = rtrim($crawlRecord->domain ?? '', '/');
$gscProperty = null;
$gscPropertyId = null;
$allGscProperties = [];

try {
    $stmt = $pdo->prepare("
        SELECT gp.id, gp.property_id, gp.display_name, gt.email
        FROM google_properties gp
        JOIN google_tokens gt ON gt.id = gp.token_id
        WHERE gp.type = 'gsc'
        ORDER BY gp.id
    ");
    $stmt->execute();
    $allGscProperties = $stmt->fetchAll(PDO::FETCH_OBJ);

    foreach ($allGscProperties as $prop) {
        $propDomain = rtrim(str_replace(['https://', 'http://', 'sc-domain:'], '', $prop->property_id), '/');
        if ($propDomain === $crawlDomain || strpos($crawlDomain, $propDomain) !== false || strpos($propDomain, $crawlDomain) !== false) {
            $gscProperty = $prop;
            $gscPropertyId = $prop->id;
            break;
        }
    }

    if (!$gscProperty && !empty($allGscProperties) && isset($_GET['gsc_property'])) {
        $requestedPropId = (int)$_GET['gsc_property'];
        foreach ($allGscProperties as $prop) {
            if ($prop->id === $requestedPropId) {
                $gscProperty = $prop;
                $gscPropertyId = $prop->id;
                break;
            }
        }
    }
} catch (Exception $e) {}

if (!$gscProperty) {
    ?>
    <h1 class="page-title">Search Console</h1>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <h2 style="margin-bottom: 1rem; color: var(--text-primary);">Aucune propriété Search Console</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
            Aucune propriété GSC ne correspond au domaine <strong><?= htmlspecialchars($crawlDomain) ?></strong>.
        </p>
        <a href="<?= scouter_google_url() ?>/auth/google" target="_blank" 
           style="display: inline-block; padding: 0.75rem 1.5rem; background: var(--primary-color); color: white; border-radius: 8px; text-decoration: none;">
            Connecter Google
        </a>
        <?php if (!empty($allGscProperties)): ?>
        <div style="margin-top: 2rem; padding: 1rem; background: rgba(251, 188, 5, 0.1); border: 1px solid rgba(251, 188, 5, 0.3); border-radius: 8px;">
            <p style="color: #fbbc05; font-weight: 600; margin-bottom: 0.5rem;">Propriétés disponibles (domaine différent)</p>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 0.75rem;">
                Ces propriétés ne correspondent pas au crawl actuel. Le croisement Crawl × GSC ne fonctionnera pas.
            </p>
            <?php foreach ($allGscProperties as $prop): ?>
                <a href="?crawl=<?= $crawlId ?>&page=search-console&gsc_property=<?= $prop->id ?>" 
                   style="display: block; padding: 0.5rem; color: var(--text-secondary); text-decoration: none;">
                    <?= htmlspecialchars($prop->display_name) ?> (<?= htmlspecialchars($prop->email) ?>)
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return;
}

// Vérifier données
$stmt = $pdo->prepare("SELECT COUNT(*) FROM gsc_keywords WHERE property_id = :pid");
$stmt->execute([':pid' => $gscPropertyId]);
if ($stmt->fetchColumn() == 0) {
    ?>
    <h1 class="page-title">Search Console — <?= htmlspecialchars($gscProperty->display_name) ?></h1>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <div style="font-size: 3rem; margin-bottom: 1rem; color: var(--text-secondary);">
            <span class="material-symbols-outlined" style="font-size: 3rem;">sync</span>
        </div>
        <h2 style="margin-bottom: 0.75rem; color: var(--text-primary);">Données non synchronisées</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
            Synchronisez les données Search Console pour <strong><?= htmlspecialchars($gscProperty->display_name) ?></strong>
        </p>
        <button id="gsc-sync-btn" onclick="syncGSC()"
            style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem;
                   background: var(--primary-color); color: white; border: none; border-radius: 8px;
                   cursor: pointer; font-size: 1rem;">
            <span class="material-symbols-outlined" style="font-size: 1.2rem;">sync</span>
            Synchroniser
        </button>
        <div id="gsc-sync-status" style="margin-top: 1.5rem; display: none;">
            <div style="background: var(--bg-secondary, #eee); border-radius: 8px; height: 12px; overflow: hidden; margin-bottom: 0.75rem;">
                <div id="gsc-sync-bar" style="width: 0%; height: 100%; background: var(--primary-color); border-radius: 8px; transition: width 0.5s ease;"></div>
            </div>
            <p id="gsc-sync-msg" style="color: var(--text-secondary); font-size: 0.85rem;">Synchronisation...</p>
        </div>
    </div>
    <script>
    async function syncGSC() {
        const btn = document.getElementById('gsc-sync-btn');
        const status = document.getElementById('gsc-sync-status');
        const bar = document.getElementById('gsc-sync-bar');
        const msg = document.getElementById('gsc-sync-msg');

        btn.style.display = 'none';
        status.style.display = 'block';
        msg.textContent = 'Synchronisation des mots-clés...';
        bar.style.width = '10%';

        const email = '<?= addslashes($gscProperty->email) ?>';
        const propertyId = <?= $gscPropertyId ?>;

        try {
            // 1. Sync keywords
            msg.textContent = 'Synchronisation des mots-clés...';
            bar.style.width = '10%';
            await fetch('<?= scouter_google_url() ?>/gsc/sync/keywords', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, propertyId })
            });

            // 2. Sync pages
            msg.textContent = 'Synchronisation des pages...';
            bar.style.width = '40%';
            await fetch('<?= scouter_google_url() ?>/gsc/sync/pages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, propertyId })
            });

            // 3. Sync daily
            msg.textContent = 'Synchronisation des données journalières...';
            bar.style.width = '70%';
            await fetch('<?= scouter_google_url() ?>/gsc/sync/daily', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, propertyId })
            });

            // Done
            bar.style.width = '100%';
            bar.style.background = '#34a853';
            msg.innerHTML = '<span style="color: #34a853;">Synchronisation terminée ! Rechargement...</span>';
            setTimeout(() => window.location.reload(), 1000);

        } catch (err) {
            bar.style.background = '#ea4335';
            msg.innerHTML = '<span style="color: #ea4335;">Erreur : ' + err.message + '</span>';
            btn.style.display = 'inline-flex';
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1.2rem;">refresh</span> Réessayer';
        }
    }
    </script>
    <?php
    return;
}

// ============================================================================
// PARAMÈTRES & REQUÊTES
// ============================================================================

$dateRange = isset($_GET['gsc_range']) ? (int)$_GET['gsc_range'] : 30;
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-{$dateRange} days"));

// Période précédente (pour comparaison)
$prevEndDate = date('Y-m-d', strtotime("-" . ($dateRange + 1) . " days"));
$prevStartDate = date('Y-m-d', strtotime("-" . ($dateRange * 2) . " days"));

// Détecter le brand name depuis le domaine
$brandName = strtolower(preg_replace('/\.(fr|com|net|org|io|co)$/', '', $crawlDomain));
$brandName = preg_replace('/^www\./', '', $brandName);

// --- KPIs depuis gsc_daily ---
$stmt = $pdo->prepare("
    SELECT 
        SUM(clicks) as total_clicks, SUM(impressions) as total_impressions,
        ROUND(AVG(position)::numeric, 1) as avg_position,
        ROUND((SUM(clicks)::numeric / NULLIF(SUM(impressions), 0) * 100), 2) as avg_ctr
    FROM gsc_daily WHERE property_id = :pid AND date BETWEEN :start AND :end
");
$stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate]);
$kpis = $stmt->fetch(PDO::FETCH_OBJ);

// Fallback si gsc_daily vide
if (!$kpis || !$kpis->total_clicks) {
    $stmt = $pdo->prepare("
        SELECT SUM(clicks) as total_clicks, SUM(impressions) as total_impressions,
            ROUND(AVG(position)::numeric, 1) as avg_position,
            ROUND((SUM(clicks)::numeric / NULLIF(SUM(impressions), 0) * 100), 2) as avg_ctr
        FROM gsc_keywords WHERE property_id = :pid AND date BETWEEN :start AND :end
    ");
    $stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate]);
    $kpis = $stmt->fetch(PDO::FETCH_OBJ);
}

// KPIs période précédente
$stmt = $pdo->prepare("
    SELECT SUM(clicks) as total_clicks, SUM(impressions) as total_impressions,
        ROUND(AVG(position)::numeric, 1) as avg_position
    FROM gsc_daily WHERE property_id = :pid AND date BETWEEN :start AND :end
");
$stmt->execute([':pid' => $gscPropertyId, ':start' => $prevStartDate, ':end' => $prevEndDate]);
$prevKpis = $stmt->fetch(PDO::FETCH_OBJ);

// Nombre de mots-clés et pages
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT query) as total_keywords, COUNT(DISTINCT page) as total_pages
    FROM gsc_keywords WHERE property_id = :pid AND date BETWEEN :start AND :end
");
$stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate]);
$countKpis = $stmt->fetch(PDO::FETCH_OBJ);

// --- Données journalières ---
$stmt = $pdo->prepare("
    SELECT date, clicks, impressions FROM gsc_daily 
    WHERE property_id = :pid AND date BETWEEN :start AND :end ORDER BY date
");
$stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate]);
$dailyData = $stmt->fetchAll(PDO::FETCH_OBJ);

if (empty($dailyData)) {
    $stmt = $pdo->prepare("
        SELECT date, SUM(clicks) as clicks, SUM(impressions) as impressions
        FROM gsc_keywords WHERE property_id = :pid AND date BETWEEN :start AND :end
        GROUP BY date ORDER BY date
    ");
    $stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate]);
    $dailyData = $stmt->fetchAll(PDO::FETCH_OBJ);
}

// --- Distribution positions ---
$stmt = $pdo->prepare("
    SELECT position_range, COUNT(*) as count FROM (
        SELECT CASE 
            WHEN avg_pos <= 3 THEN 'Top 3'
            WHEN avg_pos <= 10 THEN 'Top 4-10'
            WHEN avg_pos <= 20 THEN 'Top 11-20'
            WHEN avg_pos <= 50 THEN 'Top 21-50'
            ELSE '50+'
        END as position_range, avg_pos
        FROM (SELECT query, AVG(position) as avg_pos FROM gsc_keywords
            WHERE property_id = :pid AND date BETWEEN :start AND :end GROUP BY query) sub
    ) sub2 GROUP BY position_range
");
$stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate]);
$positionDistribution = $stmt->fetchAll(PDO::FETCH_OBJ);

// --- Branded vs Non-branded ---
$stmt = $pdo->prepare("
    SELECT 
        CASE WHEN LOWER(query) LIKE :brand THEN 'Branded' ELSE 'Non-branded' END as type,
        SUM(clicks) as clicks, SUM(impressions) as impressions
    FROM gsc_keywords WHERE property_id = :pid AND date BETWEEN :start AND :end
    GROUP BY type
");
$stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate, ':brand' => "%{$brandName}%"]);
$brandedData = $stmt->fetchAll(PDO::FETCH_OBJ);

// --- Mots-clés gagnés / perdus ---
$stmt = $pdo->prepare("
    SELECT 
        curr.query,
        curr.clicks as current_clicks,
        curr.impressions as current_impressions,
        curr.position as current_position,
        prev.clicks as prev_clicks,
        prev.position as prev_position,
        (curr.clicks - COALESCE(prev.clicks, 0)) as clicks_diff,
        (COALESCE(prev.position, 100) - curr.position) as position_diff
    FROM (
        SELECT query, SUM(clicks) as clicks, SUM(impressions) as impressions, ROUND(AVG(position)::numeric, 1) as position
        FROM gsc_keywords WHERE property_id = :pid AND date BETWEEN :start1 AND :end1 GROUP BY query
    ) curr
    LEFT JOIN (
        SELECT query, SUM(clicks) as clicks, ROUND(AVG(position)::numeric, 1) as position
        FROM gsc_keywords WHERE property_id = :pid2 AND date BETWEEN :start2 AND :end2 GROUP BY query
    ) prev ON curr.query = prev.query
    ORDER BY clicks_diff DESC
    LIMIT 20
");
$stmt->execute([
    ':pid' => $gscPropertyId, ':start1' => $startDate, ':end1' => $endDate,
    ':pid2' => $gscPropertyId, ':start2' => $prevStartDate, ':end2' => $prevEndDate
]);
$gainedKeywords = $stmt->fetchAll(PDO::FETCH_OBJ);

// Mots-clés perdus
$stmt = $pdo->prepare("
    SELECT 
        curr.query,
        curr.clicks as current_clicks,
        curr.position as current_position,
        prev.clicks as prev_clicks,
        prev.position as prev_position,
        (curr.clicks - COALESCE(prev.clicks, 0)) as clicks_diff,
        (COALESCE(prev.position, 100) - curr.position) as position_diff
    FROM (
        SELECT query, SUM(clicks) as clicks, ROUND(AVG(position)::numeric, 1) as position
        FROM gsc_keywords WHERE property_id = :pid AND date BETWEEN :start1 AND :end1 GROUP BY query
    ) curr
    LEFT JOIN (
        SELECT query, SUM(clicks) as clicks, ROUND(AVG(position)::numeric, 1) as position
        FROM gsc_keywords WHERE property_id = :pid2 AND date BETWEEN :start2 AND :end2 GROUP BY query
    ) prev ON curr.query = prev.query
    WHERE prev.clicks IS NOT NULL
    ORDER BY clicks_diff ASC
    LIMIT 20
");
$stmt->execute([
    ':pid' => $gscPropertyId, ':start1' => $startDate, ':end1' => $endDate,
    ':pid2' => $gscPropertyId, ':start2' => $prevStartDate, ':end2' => $prevEndDate
]);
$lostKeywords = $stmt->fetchAll(PDO::FETCH_OBJ);

// --- Top mots-clés ---
$stmt = $pdo->prepare("
    SELECT query, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions,
        ROUND(AVG(position)::numeric, 1) as avg_position,
        ROUND((SUM(clicks)::numeric / NULLIF(SUM(impressions), 0) * 100), 2) as ctr
    FROM gsc_keywords WHERE property_id = :pid AND date BETWEEN :start AND :end
    GROUP BY query ORDER BY total_clicks DESC LIMIT 100
");
$stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate]);
$topKeywords = $stmt->fetchAll(PDO::FETCH_OBJ);

// --- Top pages ---
$stmt = $pdo->prepare("
    SELECT page, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions,
        ROUND(AVG(position)::numeric, 1) as avg_position,
        ROUND((SUM(clicks)::numeric / NULLIF(SUM(impressions), 0) * 100), 2) as ctr
    FROM gsc_pages WHERE property_id = :pid AND date BETWEEN :start AND :end
    GROUP BY page ORDER BY total_clicks DESC LIMIT 100
");
$stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate]);
$topPages = $stmt->fetchAll(PDO::FETCH_OBJ);

// --- CROISEMENT CRAWL × SEARCH CONSOLE ---
// Pages GSC avec données crawl
$crossData = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            gsc.page,
            gsc.total_clicks,
            gsc.total_impressions,
            gsc.avg_position,
            p.code,
            p.title,
            p.compliant,
            p.word_count,
            p.inlinks,
            p.depth
        FROM (
            SELECT page, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions,
                ROUND(AVG(position)::numeric, 1) as avg_position
            FROM gsc_pages WHERE property_id = :pid AND date BETWEEN :start AND :end
            GROUP BY page
        ) gsc
        LEFT JOIN pages p ON p.crawl_id = :crawl_id AND p.url = gsc.page
        ORDER BY gsc.total_clicks DESC
        LIMIT 200
    ");
    $stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate, ':crawl_id' => $crawlId]);
    $crossData = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) {
    // Table pages peut ne pas avoir les bonnes colonnes
}

// Pages avec impressions mais erreurs (non-200)
$errorPagesWithTraffic = array_filter($crossData, function($row) {
    return $row->code && $row->code != 200 && $row->total_impressions > 0;
});

// Pages avec clics mais non indexables
$nonIndexableWithClicks = array_filter($crossData, function($row) {
    return isset($row->compliant) && !$row->compliant && $row->total_clicks > 0;
});

// Pages crawlées mais 0 clics GSC (orphelines SEO)
$crawledNoClicks = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.url, p.title, p.word_count, p.inlinks, p.depth
        FROM pages p
        LEFT JOIN (
            SELECT page, SUM(clicks) as clicks
            FROM gsc_pages WHERE property_id = :pid AND date BETWEEN :start AND :end
            GROUP BY page
        ) gsc ON p.url = gsc.page
        WHERE p.crawl_id = :crawl_id AND p.compliant = true AND p.code = 200
            AND (gsc.clicks IS NULL OR gsc.clicks = 0)
        ORDER BY p.inlinks DESC
        LIMIT 50
    ");
    $stmt->execute([':pid' => $gscPropertyId, ':start' => $startDate, ':end' => $endDate, ':crawl_id' => $crawlId]);
    $crawledNoClicks = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) {}

// Pages GSC non trouvées dans le crawl
$gscNotInCrawl = array_filter($crossData, function($row) {
    return $row->code === null && $row->total_clicks > 0;
});

// ============================================================================
// AFFICHAGE
// ============================================================================

// Helpers pour les tendances
function trendBadge($current, $previous, $inverse = false) {
    if (!$previous || $previous == 0) return '';
    $diff = $current - $previous;
    $pct = round(($diff / abs($previous)) * 100, 1);
    $isPositive = $inverse ? $diff < 0 : $diff > 0;
    $color = $isPositive ? '#34a853' : '#ea4335';
    $arrow = $diff > 0 ? '↑' : '↓';
    return "<span style='font-size: 0.75rem; color: {$color}; margin-left: 0.25rem;'>{$arrow} {$pct}%</span>";
}
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
    <h1 class="page-title" style="margin: 0;">Search Console — <?= htmlspecialchars($gscProperty->display_name) ?></h1>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <button id="gsc-resync-btn" onclick="resyncGSC()" title="Resynchroniser les données"
            style="display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.35rem 0.75rem;
                   background: none; border: 1px solid var(--border-color); border-radius: 6px;
                   cursor: pointer; font-size: 0.85rem; color: var(--text-secondary);">
            <span class="material-symbols-outlined" style="font-size: 1rem;">sync</span>
            Sync
        </button>
        <span style="color: var(--text-secondary); font-size: 0.85rem;">Période :</span>
        <?php foreach ([7 => '7j', 30 => '30j', 90 => '90j'] as $days => $label): ?>
            <a href="?crawl=<?= $crawlId ?>&page=search-console&gsc_range=<?= $days ?><?= $gscPropertyId ? '&gsc_property=' . $gscPropertyId : '' ?>"
               style="padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.85rem; text-decoration: none;
                      <?= $dateRange == $days ? 'background: var(--primary-color); color: white;' : 'background: var(--bg-secondary); color: var(--text-secondary);' ?>">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<script>
async function resyncGSC() {
    const btn = document.getElementById('gsc-resync-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1rem; animation: gsc-spin 1s linear infinite;">sync</span> Sync...';

    const email = '<?= addslashes($gscProperty->email) ?>';
    const propertyId = <?= $gscPropertyId ?>;

    try {
        await fetch('<?= scouter_google_url() ?>/sync/all', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, gscPropertyId: propertyId })
        });
        window.location.reload();
    } catch (err) {
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1rem;">sync_problem</span> Erreur';
        btn.disabled = false;
        setTimeout(() => {
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1rem;">sync</span> Sync';
        }, 3000);
    }
}
</script>
<style>@keyframes gsc-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ===== KPIs ===== -->
    <div class="scorecards">
        <?php
        Component::card(['color' => 'info', 'icon' => 'search', 'title' => 'Mots-clés',
            'value' => number_format($countKpis->total_keywords ?? 0), 'desc' => 'Mots-clés avec impressions']);
        Component::card(['color' => 'success', 'icon' => 'ads_click', 'title' => 'Clics',
            'value' => number_format($kpis->total_clicks ?? 0) . trendBadge($kpis->total_clicks, $prevKpis->total_clicks ?? 0),
            'desc' => 'Clics sur la période']);
        Component::card(['color' => 'warning', 'icon' => 'visibility', 'title' => 'Impressions',
            'value' => number_format($kpis->total_impressions ?? 0) . trendBadge($kpis->total_impressions, $prevKpis->total_impressions ?? 0),
            'desc' => 'Impressions totales']);
        Component::card(['color' => 'color1', 'icon' => 'trending_up', 'title' => 'Position moy.',
            'value' => ($kpis->avg_position ?? 0) . trendBadge($kpis->avg_position, $prevKpis->avg_position ?? 0, true),
            'desc' => 'Tous mots-clés']);
        Component::card(['color' => 'info', 'icon' => 'percent', 'title' => 'CTR moyen',
            'value' => ($kpis->avg_ctr ?? 0) . '%', 'desc' => 'Taux de clic']);
        Component::card(['color' => 'success', 'icon' => 'description', 'title' => 'Pages',
            'value' => number_format($countKpis->total_pages ?? 0), 'desc' => 'Pages avec trafic']);
        ?>
    </div>

    <!-- ===== Graphiques ligne 1 : Évolution + Positions ===== -->
    <div class="charts-grid">
        <?php
        $dates = []; $clicksData = []; $impressionsData = [];
        foreach ($dailyData as $day) {
            $dates[] = date('d/m', strtotime($day->date));
            $clicksData[] = (int)$day->clicks;
            $impressionsData[] = (int)$day->impressions;
        }
        Component::chart([
            'type' => 'line', 'title' => 'Évolution des clics et impressions',
            'subtitle' => "Du " . date('d/m/Y', strtotime($startDate)) . " au " . date('d/m/Y', strtotime($endDate)),
            'categories' => $dates,
            'series' => [
                ['name' => 'Clics', 'data' => $clicksData, 'color' => '#4285f4'],
                ['name' => 'Impressions', 'data' => $impressionsData, 'color' => '#34a853']
            ],
            'height' => 350
        ]);

        $posColors = ['#4285f4', '#34a853', '#fbbc05', '#ea4335', '#9e9e9e'];
        $posDonut = []; $i = 0;
        foreach ($positionDistribution as $pos) {
            $posDonut[] = ['name' => $pos->position_range, 'y' => (int)$pos->count, 'color' => $posColors[$i++ % 5]];
        }
        Component::chart([
            'type' => 'donut', 'title' => 'Distribution des positions',
            'subtitle' => 'Répartition par tranche',
            'series' => [['name' => 'Mots-clés', 'data' => $posDonut]],
            'height' => 350, 'legendPosition' => 'bottom'
        ]);
        ?>
    </div>

    <!-- ===== Graphiques ligne 2 : Branded + Top pages bar ===== -->
    <div class="charts-grid">
        <?php
        // Branded vs Non-branded (donut clics)
        $brandDonut = [];
        foreach ($brandedData as $bd) {
            $color = $bd->type === 'Branded' ? '#4285f4' : '#34a853';
            $brandDonut[] = ['name' => $bd->type . ' (' . number_format($bd->clicks) . ' clics)', 'y' => (int)$bd->clicks, 'color' => $color];
        }
        if (!empty($brandDonut)) {
            Component::chart([
                'type' => 'donut', 'title' => 'Branded vs Non-branded',
                'subtitle' => "Basé sur le terme \"$brandName\"",
                'series' => [['name' => 'Clics', 'data' => $brandDonut]],
                'height' => 350, 'legendPosition' => 'bottom'
            ]);
        }

        // Top 10 pages (horizontal bar)
        $top10Pages = array_slice($topPages, 0, 10);
        $pageLabels = []; $pageClicks = [];
        foreach (array_reverse($top10Pages) as $pg) {
            $path = parse_url($pg->page, PHP_URL_PATH) ?: $pg->page;
            $pageLabels[] = strlen($path) > 40 ? substr($path, 0, 37) . '...' : $path;
            $pageClicks[] = (int)$pg->total_clicks;
        }
        Component::chart([
            'type' => 'horizontalBar', 'title' => 'Top 10 pages par clics',
            'subtitle' => 'Pages avec le plus de clics',
            'categories' => $pageLabels,
            'series' => [['name' => 'Clics', 'data' => $pageClicks, 'color' => '#4285f4']],
            'height' => 350
        ]);
        ?>
    </div>

    <!-- ===== Mots-clés gagnés ===== -->
    <?php
    $gainedTableData = [];
    foreach ($gainedKeywords as $kw) {
        if ($kw->clicks_diff <= 0) continue;
        $gainedTableData[] = [
            'query' => $kw->query,
            'clicks' => number_format($kw->current_clicks),
            'prev_clicks' => number_format($kw->prev_clicks ?? 0),
            'diff' => '+' . number_format($kw->clicks_diff),
            'position' => $kw->current_position
        ];
    }
    if (!empty($gainedTableData)) {
        Component::simpleTable([
            'title' => 'Mots-clés en hausse',
            'subtitle' => "Comparaison avec la période précédente ({$dateRange}j vs {$dateRange}j)",
            'columns' => [
                ['key' => 'query', 'label' => 'Mot-clé', 'type' => 'bold'],
                ['key' => 'clicks', 'label' => 'Clics actuels', 'type' => 'default'],
                ['key' => 'prev_clicks', 'label' => 'Clics précédents', 'type' => 'default'],
                ['key' => 'diff', 'label' => 'Différence', 'type' => 'default'],
                ['key' => 'position', 'label' => 'Position', 'type' => 'default']
            ],
            'data' => $gainedTableData
        ]);
    }
    ?>

    <!-- ===== Mots-clés perdus ===== -->
    <?php
    $lostTableData = [];
    foreach ($lostKeywords as $kw) {
        if ($kw->clicks_diff >= 0) continue;
        $lostTableData[] = [
            'query' => $kw->query,
            'clicks' => number_format($kw->current_clicks),
            'prev_clicks' => number_format($kw->prev_clicks ?? 0),
            'diff' => number_format($kw->clicks_diff),
            'position' => $kw->current_position
        ];
    }
    if (!empty($lostTableData)) {
        Component::simpleTable([
            'title' => 'Mots-clés en baisse',
            'subtitle' => "Comparaison avec la période précédente ({$dateRange}j vs {$dateRange}j)",
            'columns' => [
                ['key' => 'query', 'label' => 'Mot-clé', 'type' => 'bold'],
                ['key' => 'clicks', 'label' => 'Clics actuels', 'type' => 'default'],
                ['key' => 'prev_clicks', 'label' => 'Clics précédents', 'type' => 'default'],
                ['key' => 'diff', 'label' => 'Différence', 'type' => 'default'],
                ['key' => 'position', 'label' => 'Position', 'type' => 'default']
            ],
            'data' => $lostTableData
        ]);
    }
    ?>

    <!-- ===== Top mots-clés ===== -->
    <?php
    $kwTableData = [];
    foreach ($topKeywords as $kw) {
        $kwTableData[] = [
            'query' => $kw->query,
            'clicks' => number_format($kw->total_clicks),
            'impressions' => number_format($kw->total_impressions),
            'position' => $kw->avg_position,
            'ctr' => $kw->ctr . '%'
        ];
    }
    Component::simpleTable([
        'title' => 'Top mots-clés',
        'subtitle' => 'Les ' . count($topKeywords) . ' mots-clés avec le plus de clics',
        'columns' => [
            ['key' => 'query', 'label' => 'Mot-clé', 'type' => 'bold'],
            ['key' => 'clicks', 'label' => 'Clics', 'type' => 'default'],
            ['key' => 'impressions', 'label' => 'Impressions', 'type' => 'default'],
            ['key' => 'position', 'label' => 'Position', 'type' => 'default'],
            ['key' => 'ctr', 'label' => 'CTR', 'type' => 'default']
        ],
        'data' => $kwTableData
    ]);
    ?>

    <!-- ===== Top pages ===== -->
    <?php
    $pgTableData = [];
    foreach ($topPages as $pg) {
        $pgTableData[] = [
            'page' => parse_url($pg->page, PHP_URL_PATH) ?: $pg->page,
            'clicks' => number_format($pg->total_clicks),
            'impressions' => number_format($pg->total_impressions),
            'position' => $pg->avg_position,
            'ctr' => $pg->ctr . '%'
        ];
    }
    Component::simpleTable([
        'title' => 'Top pages',
        'subtitle' => 'Les ' . count($topPages) . ' pages avec le plus de clics',
        'columns' => [
            ['key' => 'page', 'label' => 'Page', 'type' => 'bold'],
            ['key' => 'clicks', 'label' => 'Clics', 'type' => 'default'],
            ['key' => 'impressions', 'label' => 'Impressions', 'type' => 'default'],
            ['key' => 'position', 'label' => 'Position', 'type' => 'default'],
            ['key' => 'ctr', 'label' => 'CTR', 'type' => 'default']
        ],
        'data' => $pgTableData
    ]);
    ?>

    <!-- ===== CROISEMENT CRAWL × SEARCH CONSOLE ===== -->
    <?php if (!empty($crossData)): ?>
    <h2 style="margin: 1rem 0 0; color: var(--text-primary); font-size: 1.3rem;">Crawl × Search Console</h2>

    <!-- KPIs croisement -->
    <div class="scorecards">
        <?php
        $matchedPages = count(array_filter($crossData, fn($r) => $r->code !== null));
        $unmatchedPages = count($gscNotInCrawl);
        $errorCount = count($errorPagesWithTraffic);
        $orphanCount = count($crawledNoClicks);

        Component::card(['color' => 'success', 'icon' => 'check_circle', 'title' => 'Pages croisées',
            'value' => $matchedPages, 'desc' => 'Pages présentes dans le crawl et GSC']);
        Component::card(['color' => 'warning', 'icon' => 'help_outline', 'title' => 'GSC hors crawl',
            'value' => $unmatchedPages, 'desc' => 'Pages GSC non trouvées dans le crawl']);
        Component::card(['color' => 'error', 'icon' => 'error', 'title' => 'Erreurs avec trafic',
            'value' => $errorCount, 'desc' => 'Pages non-200 ayant du trafic GSC']);
        Component::card(['color' => 'color1', 'icon' => 'visibility_off', 'title' => 'Orphelines SEO',
            'value' => $orphanCount, 'desc' => 'Pages indexables sans aucun clic']);
        ?>
    </div>

    <!-- Pages en erreur avec du trafic -->
    <?php if (!empty($errorPagesWithTraffic)):
        $errorTableData = [];
        foreach ($errorPagesWithTraffic as $row) {
            $errorTableData[] = [
                'page' => parse_url($row->page, PHP_URL_PATH) ?: $row->page,
                'code' => $row->code,
                'clicks' => number_format($row->total_clicks),
                'impressions' => number_format($row->total_impressions),
                'position' => $row->avg_position
            ];
        }
        Component::simpleTable([
            'title' => 'Pages en erreur avec du trafic',
            'subtitle' => 'Pages non-200 qui reçoivent encore des clics — à corriger en priorité',
            'columns' => [
                ['key' => 'page', 'label' => 'Page', 'type' => 'bold'],
                ['key' => 'code', 'label' => 'Code HTTP', 'type' => 'default'],
                ['key' => 'clicks', 'label' => 'Clics', 'type' => 'default'],
                ['key' => 'impressions', 'label' => 'Impressions', 'type' => 'default'],
                ['key' => 'position', 'label' => 'Position', 'type' => 'default']
            ],
            'data' => $errorTableData
        ]);
    endif; ?>

    <!-- Pages orphelines (indexables sans clics) -->
    <?php if (!empty($crawledNoClicks)):
        $orphanTableData = [];
        foreach (array_slice($crawledNoClicks, 0, 30) as $row) {
            $orphanTableData[] = [
                'page' => parse_url($row->url, PHP_URL_PATH) ?: $row->url,
                'title' => mb_substr($row->title ?? '', 0, 50),
                'words' => number_format($row->word_count ?? 0),
                'inlinks' => $row->inlinks ?? 0,
                'depth' => $row->depth ?? '-'
            ];
        }
        Component::simpleTable([
            'title' => 'Pages orphelines SEO (' . count($crawledNoClicks) . ')',
            'subtitle' => 'Pages indexables sans aucun clic GSC — manque de maillage ou contenu faible',
            'columns' => [
                ['key' => 'page', 'label' => 'Page', 'type' => 'bold'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'default'],
                ['key' => 'words', 'label' => 'Mots', 'type' => 'default'],
                ['key' => 'inlinks', 'label' => 'Inlinks', 'type' => 'default'],
                ['key' => 'depth', 'label' => 'Profondeur', 'type' => 'default']
            ],
            'data' => $orphanTableData
        ]);
    endif; ?>

    <?php endif; // fin crossData ?>

</div>