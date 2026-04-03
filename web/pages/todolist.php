<?php
/**
 * ============================================================================
 * PAGE : Todolist SEO priorisée
 * ============================================================================
 * Génère automatiquement une liste de problèmes SEO détectés,
 * classés par priorité (impact × facilité).
 */

// ============================================================================
// COLLECTE DES DONNÉES
// ============================================================================

$issues = [];

// Helper pour ajouter un problème
function addIssue(&$issues, $category, $title, $count, $impact, $difficulty, $description, $page = null) {
    if ($count <= 0) return;
    
    // Impact : 5=très fort, 4=fort, 3=moyen, 2=faible, 1=très faible
    // Difficulty : 1=très facile, 2=facile, 3=moyenne, 4=difficile, 5=très difficile
    // Priority = impact + (6 - difficulty) → plus c'est haut, plus c'est prioritaire
    $priority = $impact + (6 - $difficulty);
    
    $impactLabels = [5 => 'Très fort', 4 => 'Fort', 3 => 'Moyen', 2 => 'Faible', 1 => 'Très faible'];
    $diffLabels = [1 => 'Très facile', 2 => 'Facile', 3 => 'Moyenne', 4 => 'Difficile', 5 => 'Très difficile'];
    
    $issues[] = [
        'category' => $category,
        'title' => $title,
        'count' => $count,
        'impact' => $impact,
        'impact_label' => $impactLabels[$impact] ?? '?',
        'difficulty' => $difficulty,
        'difficulty_label' => $diffLabels[$difficulty] ?? '?',
        'priority' => $priority,
        'description' => $description,
        'page' => $page,
    ];
}

// --- Pages internes ---
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND external = false AND crawled = true");
$stmt->execute([':cid' => $crawlId]);
$totalInternal = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true");
$stmt->execute([':cid' => $crawlId]);
$totalCompliant = (int)$stmt->fetchColumn();

// ─── TECHNIQUE ───

// Pages 4xx
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND external = false AND code >= 400 AND code < 500");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Technique', "Pages en erreur 4xx ({$count})", $count, 5, 2,
    'Les pages en erreur 404 doivent être redirigées ou supprimées. Elles nuisent au crawl budget et à l\'expérience utilisateur.', 'codes');

// Pages 5xx
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND external = false AND code >= 500");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Technique', "Pages en erreur 5xx ({$count})", $count, 5, 3,
    'Les erreurs serveur sont critiques. Elles empêchent l\'indexation et signalent des problèmes techniques.', 'codes');

// Redirections internes
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND external = false AND code >= 300 AND code < 400");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Technique', "Redirections internes ({$count})", $count, 3, 2,
    'Les liens internes ne doivent pas pointer vers des redirections. Corrigez les liens pour pointer directement vers l\'URL finale.', 'codes');

// Pages lentes (>1s)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND external = false AND code = 200 AND response_time > 1000");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Technique', "Pages lentes >1s ({$count})", $count, 4, 4,
    'Un temps de réponse supérieur à 1 seconde dégrade l\'expérience utilisateur et le crawl. Optimisez le serveur, le cache et les requêtes.', 'response-time');

// Chaînes de redirection
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM redirect_chains WHERE crawl_id = :cid AND (is_loop = true OR hops > 1)");
    $stmt->execute([':cid' => $crawlId]);
    $count = (int)$stmt->fetchColumn();
    addIssue($issues, 'Technique', "Chaînes de redirection ({$count})", $count, 4, 2,
        'Les chaînes de redirection (A→B→C) ralentissent le crawl et diluent le PageRank. Redirigez directement vers l\'URL finale.', 'redirections');
} catch (Exception $e) {}

// ─── CONTENU ───

// Titles dupliqués
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND title_status = 'duplicate'");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Contenu', "Balises titre dupliquées ({$count})", $count, 5, 1,
    'Chaque page doit avoir un titre unique. Les titres dupliqués empêchent Google de différencier vos pages.', 'titles');

// Titles manquants
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND (title IS NULL OR title = '')");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Contenu', "Balises titre manquantes ({$count})", $count, 5, 1,
    'La balise titre est le facteur on-page le plus important. Chaque page indexable doit en avoir une.', 'titles');

// Titles trop longs
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND title IS NOT NULL AND LENGTH(title) > 60");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Contenu', "Balises titre trop longues ({$count})", $count, 3, 1,
    'Les titres de plus de 60 caractères sont tronqués dans les résultats Google. Raccourcissez-les pour un meilleur CTR.', 'titles');

// Meta descriptions dupliquées
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND metadesc_status = 'duplicate'");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Contenu', "Meta descriptions dupliquées ({$count})", $count, 4, 1,
    'Les meta descriptions dupliquées réduisent le CTR. Personnalisez chaque description.', 'meta-desc');

// Meta descriptions manquantes
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND (metadesc IS NULL OR metadesc = '')");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Contenu', "Meta descriptions manquantes ({$count})", $count, 4, 2,
    'Sans meta description, Google génère un extrait automatique souvent moins attractif.', 'meta-desc');

// H1 dupliqués
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND h1_status = 'duplicate'");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Contenu', "H1 dupliqués ({$count})", $count, 4, 1,
    'Chaque page devrait avoir un H1 unique décrivant son contenu principal.', 'h1');

// H1 manquants
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND (h1 IS NULL OR h1 = '')");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Contenu', "H1 manquants ({$count})", $count, 4, 1,
    'La balise H1 est un signal fort pour Google. Chaque page indexable doit en avoir une.', 'h1');

// H1 multiples
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND h1_multiple = true");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Contenu', "Pages avec plusieurs H1 ({$count})", $count, 3, 2,
    'Une seule balise H1 par page est recommandée pour une structure sémantique claire.', 'h1');

// Pages à faible contenu (<300 mots)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND word_count < 300 AND word_count > 0");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Contenu', "Pages à faible contenu <300 mots ({$count})", $count, 4, 4,
    'Les pages avec peu de contenu ont moins de chances de se positionner. Enrichissez le contenu ou envisagez de les fusionner.', 'word-count');

// ─── MAILLAGE ───

// Pages orphelines (0 inlinks)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND inlinks = 0");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Maillage', "Pages orphelines ({$count})", $count, 4, 2,
    'Ces pages n\'ont aucun lien interne pointant vers elles. Ajoutez des liens depuis des pages pertinentes.', 'linking');

// Pages profondes (>3)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid AND compliant = true AND depth > 3");
$stmt->execute([':cid' => $crawlId]);
$count = (int)$stmt->fetchColumn();
addIssue($issues, 'Maillage', "Pages profondes >3 clics ({$count})", $count, 3, 3,
    'Les pages importantes doivent être accessibles en 3 clics maximum. Restructurez la navigation ou ajoutez des raccourcis.', 'depth');

// ─── ENRICHISSEMENT ───

// Images sans alt
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM page_images WHERE crawl_id = :cid AND (alt IS NULL OR alt = '')");
    $stmt->execute([':cid' => $crawlId]);
    $count = (int)$stmt->fetchColumn();
    addIssue($issues, 'Enrichissement', "Images sans attribut alt ({$count})", $count, 3, 2,
        'L\'attribut alt est important pour l\'accessibilité et le référencement des images. Décrivez chaque image.', 'images');
} catch (Exception $e) {}

// Pages sans Open Graph
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM page_opengraph og
        JOIN pages p ON p.crawl_id = og.crawl_id AND p.id = og.page_id
        WHERE og.crawl_id = :cid AND p.compliant = true
        AND (og.og_title IS NULL OR og.og_title = '')
    ");
    $stmt->execute([':cid' => $crawlId]);
    $count = (int)$stmt->fetchColumn();
    addIssue($issues, 'Enrichissement', "Pages sans Open Graph ({$count})", $count, 2, 2,
        'Les balises Open Graph améliorent l\'apparence de vos pages sur les réseaux sociaux.', 'opengraph');
} catch (Exception $e) {}

// ─── SITEMAP ───

// Pages sitemap non indexables
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM sitemap_urls s
        JOIN pages p ON p.crawl_id = s.crawl_id AND p.url = s.url
        WHERE s.crawl_id = :cid AND p.compliant = false
    ");
    $stmt->execute([':cid' => $crawlId]);
    $count = (int)$stmt->fetchColumn();
    addIssue($issues, 'Sitemap', "Pages sitemap non indexables ({$count})", $count, 4, 1,
        'Votre sitemap contient des pages non indexables. Retirez-les pour ne pas gaspiller le crawl budget.', 'sitemap');
} catch (Exception $e) {}

// Pages indexables absentes du sitemap
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM pages p
        LEFT JOIN sitemap_urls s ON s.crawl_id = p.crawl_id AND s.url = p.url
        WHERE p.crawl_id = :cid AND p.compliant = true AND p.external = false AND s.id IS NULL
    ");
    $stmt->execute([':cid' => $crawlId]);
    $count = (int)$stmt->fetchColumn();
    addIssue($issues, 'Sitemap', "Pages absentes du sitemap ({$count})", $count, 3, 1,
        'Ces pages indexables ne sont pas dans votre sitemap. Ajoutez-les pour faciliter leur découverte par Google.', 'sitemap');
} catch (Exception $e) {}

// ─── PERFORMANCE ───

// PageSpeed faible (<50)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM page_pagespeed WHERE crawl_id = :cid AND score_performance < 50");
    $stmt->execute([':cid' => $crawlId]);
    $count = (int)$stmt->fetchColumn();
    addIssue($issues, 'Performance', "Pages PageSpeed <50 ({$count})", $count, 4, 5,
        'Un score PageSpeed inférieur à 50 impacte le positionnement. Optimisez les images, le JavaScript et le CSS.', 'pagespeed');
} catch (Exception $e) {}

// LCP élevé (>4s)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM page_pagespeed WHERE crawl_id = :cid AND lcp > 4");
    $stmt->execute([':cid' => $crawlId]);
    $count = (int)$stmt->fetchColumn();
    addIssue($issues, 'Performance', "Pages avec LCP >4s ({$count})", $count, 4, 4,
        'Le Largest Contentful Paint doit être inférieur à 2.5s. Optimisez le chargement du contenu principal.', 'pagespeed');
} catch (Exception $e) {}

// ─── SEARCH CONSOLE ───

// Pages avec trafic mais erreurs
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM (
            SELECT gsc.page FROM gsc_pages gsc
            JOIN google_properties gp ON gp.id = gsc.property_id
            JOIN pages p ON p.crawl_id = :cid AND p.url = gsc.page
            WHERE p.code != 200 AND gsc.date >= NOW() - INTERVAL '30 days'
            GROUP BY gsc.page HAVING SUM(gsc.clicks) > 0
        ) sub
    ");
    $stmt->execute([':cid' => $crawlId]);
    $count = (int)$stmt->fetchColumn();
    addIssue($issues, 'Search Console', "Pages en erreur avec trafic ({$count})", $count, 5, 2,
        'Ces pages reçoivent du trafic Google mais retournent des erreurs. Corrigez-les en priorité.', 'search-console');
} catch (Exception $e) {}

// ============================================================================
// TRIER PAR PRIORITÉ
// ============================================================================

usort($issues, function($a, $b) { return $b['priority'] - $a['priority']; });

// Compter les problèmes
$totalIssues = count($issues);
$todoCount = $totalIssues;
$criticalCount = count(array_filter($issues, fn($i) => $i['impact'] >= 4));

// ============================================================================
// AFFICHAGE
// ============================================================================
?>

<h1 class="page-title">Todolist SEO</h1>
<p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
    <?= $totalIssues ?> problèmes détectés dont <?= $criticalCount ?> critiques — classés par priorité
</p>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- KPIs -->
    <div class="scorecards">
        <?php
        Component::card(['color' => $totalIssues == 0 ? 'success' : 'error', 'icon' => 'checklist', 'title' => 'Problèmes détectés',
            'value' => $totalIssues, 'desc' => 'Total des problèmes']);
        Component::card(['color' => $criticalCount > 0 ? 'error' : 'success', 'icon' => 'priority_high', 'title' => 'Critiques',
            'value' => $criticalCount, 'desc' => 'Impact fort ou très fort']);

        $categories = array_unique(array_column($issues, 'category'));
        $topCategory = !empty($issues) ? $issues[0]['category'] : '-';
        Component::card(['color' => 'warning', 'icon' => 'category', 'title' => 'Catégories',
            'value' => count($categories), 'desc' => 'Catégories concernées']);
        Component::card(['color' => 'info', 'icon' => 'star', 'title' => 'Priorité #1',
            'value' => !empty($issues) ? mb_substr($issues[0]['title'], 0, 25) : 'Aucun', 'desc' => $topCategory]);
        ?>
    </div>

    <!-- Liste des problèmes -->
    <?php if (empty($issues)): ?>
        <div style="padding: 3rem; text-align: center; color: var(--text-secondary);">
            <span class="material-symbols-outlined" style="font-size: 3rem; color: #34a853;">check_circle</span>
            <h2 style="margin-top: 1rem; color: #34a853;">Aucun problème détecté</h2>
            <p>Votre site semble en bonne santé SEO. Lancez les enrichissements pour une analyse plus complète.</p>
        </div>
    <?php else: ?>

    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
        <?php
        $currentCategory = '';
        foreach ($issues as $idx => $issue):
            // Séparateur de catégorie
            if ($issue['category'] !== $currentCategory):
                $currentCategory = $issue['category'];
        ?>
            <div style="margin-top: <?= $idx > 0 ? '1rem' : '0' ?>; margin-bottom: 0.25rem;">
                <span style="font-weight: 600; font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">
                    <?= htmlspecialchars($currentCategory) ?>
                </span>
            </div>
        <?php endif; ?>

            <div style="background: var(--card-bg); border-radius: 8px; padding: 1rem 1.25rem; box-shadow: 0 1px 4px rgba(0,0,0,0.06);
                        border-left: 4px solid <?= $issue['impact'] >= 4 ? '#ea4335' : ($issue['impact'] >= 3 ? '#fbbc05' : '#4285f4') ?>;
                        display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                <div style="flex: 1;">
                    <div style="font-weight: 600; color: var(--text-primary); font-size: 0.95rem;">
                        <?= htmlspecialchars($issue['title']) ?>
                    </div>
                    <div style="color: var(--text-secondary); font-size: 0.8rem; margin-top: 0.25rem;">
                        <?= htmlspecialchars($issue['description']) ?>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-shrink: 0;">
                    <div style="text-align: center;">
                        <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase;">Impact</div>
                        <div style="font-size: 0.8rem; font-weight: 600; color: <?= $issue['impact'] >= 4 ? '#ea4335' : ($issue['impact'] >= 3 ? '#fbbc05' : '#34a853') ?>;">
                            <?= $issue['impact_label'] ?>
                        </div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase;">Difficulté</div>
                        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary);">
                            <?= $issue['difficulty_label'] ?>
                        </div>
                    </div>
                    <div style="text-align: center; min-width: 40px;">
                        <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase;">Priorité</div>
                        <div style="font-size: 1.1rem; font-weight: 700; color: <?= $issue['priority'] >= 8 ? '#ea4335' : ($issue['priority'] >= 6 ? '#fbbc05' : '#34a853') ?>;">
                            <?= $issue['priority'] ?>/10
                        </div>
                    </div>
                    <?php if ($issue['page']): ?>
                    <a href="?crawl=<?= $crawlId ?>&page=<?= $issue['page'] ?>" 
                       style="color: var(--primary-color); text-decoration: none; font-size: 0.8rem; white-space: nowrap;">
                        Voir →
                    </a>
                    <?php endif; ?>
                </div>
            </div>

        <?php endforeach; ?>
    </div>

    <?php endif; ?>

</div>
