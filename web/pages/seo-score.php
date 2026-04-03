<?php
/**
 * PAGE : Score SEO Global (avec bouton de lancement)
 */

$score = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM seo_score WHERE crawl_id = :cid");
    $stmt->execute([':cid' => $crawlId]);
    $score = $stmt->fetch();
} catch (Exception $e) {}

if (!$score) {
    ?>
    <h1 class="page-title">Score SEO Global</h1>
    <script src="/scouter-enricher/assets/enricher.js"></script>
    <div id="enricher-launch"></div>
    <script>
    EnricherUI.renderLaunchButton('enricher-launch', {
        action: 'score',
        crawlId: <?= $crawlId ?>,
        label: 'Calculer le Score SEO',
        icon: 'stars',
        description: 'Calcule un score global (0-100) basé sur la technique, le contenu, le maillage et l\'enrichissement.'
    });
    </script>
    <?php
    return;
}

$details = json_decode($score->details, true) ?: [];

function getScoreColor2($val, $max) {
    $pct = $max > 0 ? ($val / $max) * 100 : 0;
    if ($pct >= 80) return 'success';
    if ($pct >= 50) return 'warning';
    return 'error';
}

function scoreBarHtml2($val, $max, $label) {
    $pct = $max > 0 ? round(($val / $max) * 100) : 0;
    $color = $pct >= 80 ? '#34a853' : ($pct >= 50 ? '#fbbc05' : '#ea4335');
    return "<div style='margin-bottom: 0.75rem;'>
        <div style='display: flex; justify-content: space-between; margin-bottom: 0.25rem;'>
            <span style='font-size: 0.85rem; color: var(--text-primary);'>{$label}</span>
            <span style='font-size: 0.85rem; font-weight: 600; color: var(--text-primary);'>{$val}/{$max}</span>
        </div>
        <div style='background: var(--bg-secondary); border-radius: 4px; height: 8px; overflow: hidden;'>
            <div style='width: {$pct}%; height: 100%; background: {$color}; border-radius: 4px;'></div>
        </div>
    </div>";
}

?>
<h1 class="page-title">Score SEO Global</h1>
<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <div style="display: flex; gap: 2rem; align-items: center; flex-wrap: wrap;">
        <div style="position: relative; width: 180px; height: 180px; flex-shrink: 0;">
            <?php
            $totalScore = $score->score_total;
            $scoreColorHex = $totalScore >= 80 ? '#34a853' : ($totalScore >= 50 ? '#fbbc05' : '#ea4335');
            $circumference = 2 * M_PI * 75;
            $dashOffset = $circumference * (1 - $totalScore / 100);
            ?>
            <svg width="180" height="180" viewBox="0 0 180 180">
                <circle cx="90" cy="90" r="75" fill="none" stroke="var(--bg-secondary, #eee)" stroke-width="12"/>
                <circle cx="90" cy="90" r="75" fill="none" stroke="<?= $scoreColorHex ?>" stroke-width="12"
                    stroke-dasharray="<?= $circumference ?>" stroke-dashoffset="<?= $dashOffset ?>"
                    stroke-linecap="round" transform="rotate(-90 90 90)"/>
                <text x="90" y="85" text-anchor="middle" fill="var(--text-primary, #333)" font-size="42" font-weight="700"><?= $totalScore ?></text>
                <text x="90" y="110" text-anchor="middle" fill="var(--text-secondary, #666)" font-size="14">/100</text>
            </svg>
        </div>
        <div style="flex: 1; min-width: 300px;">
            <?= scoreBarHtml2($score->score_technique, 25, 'Technique') ?>
            <?= scoreBarHtml2($score->score_contenu, 25, 'Contenu') ?>
            <?= scoreBarHtml2($score->score_maillage, 25, 'Maillage interne') ?>
            <?= scoreBarHtml2($score->score_enrichment, 15, 'Enrichissement') ?>
            <?php if ($score->score_performance !== null): ?>
                <?= scoreBarHtml2($score->score_performance, 10, 'Performance') ?>
            <?php else: ?>
                <div style="margin-bottom: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <span style="font-size: 0.85rem; color: var(--text-secondary);">Performance</span>
                        <span style="font-size: 0.85rem; color: var(--text-secondary);">Non mesuré</span>
                    </div>
                    <div style="background: var(--bg-secondary); border-radius: 4px; height: 8px;"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="scorecards">
        <?php
        Component::card(['color' => getScoreColor2($score->score_technique, 25), 'icon' => 'build', 'title' => 'Technique',
            'value' => $score->score_technique . '/25', 'desc' => 'HTTP, indexabilité, temps de réponse']);
        Component::card(['color' => getScoreColor2($score->score_contenu, 25), 'icon' => 'article', 'title' => 'Contenu',
            'value' => $score->score_contenu . '/25', 'desc' => 'Balises SEO, richesse, duplication']);
        Component::card(['color' => getScoreColor2($score->score_maillage, 25), 'icon' => 'hub', 'title' => 'Maillage',
            'value' => $score->score_maillage . '/25', 'desc' => 'Profondeur, inlinks, redirections']);
        Component::card(['color' => getScoreColor2($score->score_enrichment, 15), 'icon' => 'auto_awesome', 'title' => 'Enrichissement',
            'value' => $score->score_enrichment . '/15', 'desc' => 'Images, Open Graph, schemas']);
        ?>
    </div>

    <div class="charts-grid">
        <?php
        $radarData = [
            ['name' => 'Technique (' . $score->score_technique . '/25)', 'y' => (int)$score->score_technique, 'color' => '#4285f4'],
            ['name' => 'Contenu (' . $score->score_contenu . '/25)', 'y' => (int)$score->score_contenu, 'color' => '#34a853'],
            ['name' => 'Maillage (' . $score->score_maillage . '/25)', 'y' => (int)$score->score_maillage, 'color' => '#fbbc05'],
            ['name' => 'Enrichissement (' . $score->score_enrichment . '/15)', 'y' => (int)$score->score_enrichment, 'color' => '#ea4335'],
        ];
        if ($score->score_performance !== null) {
            $radarData[] = ['name' => 'Performance (' . $score->score_performance . '/10)', 'y' => (int)$score->score_performance, 'color' => '#ab47bc'];
        }
        Component::chart(['type' => 'donut', 'title' => 'Répartition du score',
            'subtitle' => 'Contribution de chaque catégorie',
            'series' => [['name' => 'Points', 'data' => $radarData]],
            'height' => 350, 'legendPosition' => 'bottom']);
        ?>

        <div class="chart-card" style="overflow-y: auto;">
            <div class="chart-header"><div>
                <h3 class="chart-title">Détail des critères</h3>
                <p class="chart-subtitle">Chaque indicateur et ses points</p>
            </div></div>
            <div style="padding: 0 1rem 1rem;">
                <?php
                $criteriaLabels = [
                    'pages_200_rate' => 'Pages en 200', 'indexability_rate' => 'Taux d\'indexabilité',
                    'error_pages' => 'Pages en erreur', 'avg_response_time' => 'Temps de réponse moyen',
                    'title_unique_rate' => 'Titles uniques', 'h1_unique_rate' => 'H1 uniques',
                    'metadesc_rate' => 'Meta descriptions', 'rich_content_rate' => 'Contenu riche (300+ mots)',
                    'duplication_rate' => 'Taux de duplication', 'avg_depth' => 'Profondeur moyenne',
                    'orphan_rate' => 'Pages orphelines', 'bad_redirect_chains' => 'Redirections en erreur',
                    'pr_concentration' => 'Concentration PageRank', 'images_alt_rate' => 'Images avec alt',
                    'opengraph_rate' => 'Open Graph', 'schema_rate' => 'Données structurées',
                    'pagespeed_score' => 'Score PageSpeed', 'lcp' => 'LCP', 'cls' => 'CLS',
                ];
                $sections = [
                    'Technique' => $details['technique'] ?? [], 'Contenu' => $details['contenu'] ?? [],
                    'Maillage' => $details['maillage'] ?? [], 'Enrichissement' => $details['enrichment'] ?? [],
                    'Performance' => $details['performance'] ?? [],
                ];
                foreach ($sections as $sectionName => $sectionData):
                    if (empty($sectionData)) continue;
                ?>
                    <div style="margin-bottom: 1rem;">
                        <div style="font-weight: 600; font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.5px;"><?= $sectionName ?></div>
                        <?php foreach ($sectionData as $key => $item):
                            $label = $criteriaLabels[$key] ?? $key;
                            $suffix = is_numeric($item['value']) && $item['value'] <= 100 && strpos($key, 'rate') !== false ? '%' : '';
                            if (strpos($key, 'time') !== false) $suffix = 'ms';
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.4rem 0; border-bottom: 1px solid var(--border-color);">
                                <span style="font-size: 0.85rem; color: var(--text-primary);"><?= $label ?></span>
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="font-size: 0.8rem; color: var(--text-secondary);"><?= $item['value'] . $suffix ?></span>
                                    <span style="font-size: 0.8rem; font-weight: 600; color: <?= $item['points'] >= $item['max'] * 0.8 ? '#34a853' : ($item['points'] >= $item['max'] * 0.5 ? '#fbbc05' : '#ea4335') ?>;"><?= $item['points'] ?>/<?= $item['max'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
