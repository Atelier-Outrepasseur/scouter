<?php
/**
 * PAGE : Export des données
 */

// Récupérer l'email du compte Google connecté
$googleEmail = null;
try {
    $stmt = $pdo->query("SELECT email FROM google_tokens ORDER BY updated_at DESC LIMIT 1");
    $row = $stmt->fetch();
    if ($row) $googleEmail = $row->email;
} catch (Exception $e) {}

?>

<h1 class="page-title">Export des données</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem; max-width: 800px;">

    <!-- Export Google Sheets -->
    <div style="background: var(--card-bg); border-radius: 12px; padding: 2rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
            <span class="material-symbols-outlined" style="font-size: 2rem; color: #34a853;">table_chart</span>
            <div>
                <h2 style="margin: 0; color: var(--text-primary); font-size: 1.2rem;">Google Sheets</h2>
                <p style="margin: 0.25rem 0 0; color: var(--text-secondary); font-size: 0.85rem;">
                    Exporte toutes les données du crawl dans un Google Sheets (format audit SEO)
                </p>
            </div>
        </div>

        <div style="background: var(--bg-secondary); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.85rem; color: var(--text-secondary);">
            <strong>Onglets générés :</strong> Index, Todo, Codes de réponse, Balises Titres, Balises Desc, H1,
            Nombre de mots, Temps de réponse, Profondeur, Maillage interne, Données structurées,
            Images, Hreflang, PageSpeed
        </div>

        <?php if ($googleEmail): ?>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <button id="export-sheets-btn" onclick="exportSheets()"
                    style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem;
                           background: #34a853; color: white; border: none; border-radius: 8px;
                           cursor: pointer; font-size: 1rem;">
                    <span class="material-symbols-outlined" style="font-size: 1.2rem;">cloud_upload</span>
                    Exporter vers Google Sheets
                </button>
                <span style="color: var(--text-secondary); font-size: 0.85rem;">
                    Compte : <?= htmlspecialchars($googleEmail) ?>
                </span>
            </div>
            <div id="export-sheets-status" style="margin-top: 1rem; display: none;"></div>
        <?php else: ?>
            <p style="color: var(--text-secondary);">
                Connectez votre compte Google pour exporter.
            </p>
            <a href="<?= scouter_google_url() ?>/auth/google" target="_blank"
               style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem;
                      background: var(--primary-color); color: white; border: none; border-radius: 8px;
                      text-decoration: none; font-size: 1rem;">
                <span class="material-symbols-outlined" style="font-size: 1.2rem;">login</span>
                Connecter Google
            </a>
        <?php endif; ?>
    </div>

    <!-- Export Google Slides -->
    <div style="background: var(--card-bg); border-radius: 12px; padding: 2rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
            <span class="material-symbols-outlined" style="font-size: 2rem; color: #fbbc05;">slideshow</span>
            <div>
                <h2 style="margin: 0; color: var(--text-primary); font-size: 1.2rem;">Google Slides</h2>
                <p style="margin: 0.25rem 0 0; color: var(--text-secondary); font-size: 0.85rem;">
                    Génère une présentation d'audit SEO avec les KPIs, analyses et recommandations
                </p>
            </div>
        </div>

        <div style="background: var(--bg-secondary); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.85rem; color: var(--text-secondary);">
            <strong>Slides générées :</strong> Titre, Vue d'ensemble, Codes de réponse, Indexabilité, Balises titre,
            Meta descriptions, H1, Nombre de mots, Temps de réponse, Profondeur, Maillage, Images, PageSpeed, Sitemap, Todolist
        </div>

        <?php if ($googleEmail): ?>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <button id="export-slides-btn" onclick="exportSlides()"
                    style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem;
                           background: #fbbc05; color: #333; border: none; border-radius: 8px;
                           cursor: pointer; font-size: 1rem; font-weight: 600;">
                    <span class="material-symbols-outlined" style="font-size: 1.2rem;">slideshow</span>
                    Exporter vers Google Slides
                </button>
            </div>
            <div id="export-slides-status" style="margin-top: 1rem; display: none;"></div>
        <?php else: ?>
            <p style="color: var(--text-secondary);">Connectez votre compte Google pour exporter.</p>
        <?php endif; ?>
    </div>

</div>

<script>
async function exportSheets() {
    const btn = document.getElementById('export-sheets-btn');
    const status = document.getElementById('export-sheets-status');

    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1.2rem; animation: spin 1s linear infinite;">sync</span> Export en cours...';
    status.style.display = 'block';
    status.innerHTML = '<p style="color: var(--text-secondary);">Création du Google Sheets et remplissage des données...</p>';

    try {
        const response = await fetch('<?= scouter_google_url() ?>/export/sheets', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: '<?= addslashes($googleEmail) ?>',
                crawlId: <?= $crawlId ?>
            })
        });

        const data = await response.json();

        if (data.success && data.url) {
            status.innerHTML = `
                <div style="padding: 1rem; background: rgba(52, 168, 83, 0.1); border: 1px solid rgba(52, 168, 83, 0.3); border-radius: 8px;">
                    <p style="color: #34a853; font-weight: 600; margin-bottom: 0.5rem;">Export terminé !</p>
                    <a href="${data.url}" target="_blank" style="color: var(--primary-color); text-decoration: none; font-size: 0.95rem;">
                        <span class="material-symbols-outlined" style="font-size: 1rem; vertical-align: middle;">open_in_new</span>
                        Ouvrir le Google Sheets
                    </a>
                </div>
            `;
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1.2rem;">cloud_upload</span> Exporter à nouveau';
            btn.disabled = false;
            btn.style.opacity = '1';
        } else {
            throw new Error(data.error || 'Erreur inconnue');
        }
    } catch (err) {
        status.innerHTML = `<p style="color: #ea4335;">Erreur : ${err.message}</p>`;
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1.2rem;">cloud_upload</span> Réessayer';
        btn.disabled = false;
        btn.style.opacity = '1';
    }
}

async function exportSlides() {
    const btn = document.getElementById('export-slides-btn');
    const status = document.getElementById('export-slides-status');

    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1.2rem; animation: spin 1s linear infinite;">sync</span> Génération en cours...';
    status.style.display = 'block';
    status.innerHTML = '<p style="color: var(--text-secondary);">Création de la présentation Google Slides...</p>';

    try {
        const response = await fetch('<?= scouter_google_url() ?>/export/slides', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: '<?= addslashes($googleEmail) ?>',
                crawlId: <?= $crawlId ?>
            })
        });

        const data = await response.json();

        if (data.success && data.url) {
            status.innerHTML = `
                <div style="padding: 1rem; background: rgba(251, 188, 5, 0.1); border: 1px solid rgba(251, 188, 5, 0.3); border-radius: 8px;">
                    <p style="color: #f59e0b; font-weight: 600; margin-bottom: 0.5rem;">Présentation créée !</p>
                    <a href="${data.url}" target="_blank" style="color: var(--primary-color); text-decoration: none; font-size: 0.95rem;">
                        <span class="material-symbols-outlined" style="font-size: 1rem; vertical-align: middle;">open_in_new</span>
                        Ouvrir la présentation
                    </a>
                </div>
            `;
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1.2rem;">slideshow</span> Exporter à nouveau';
            btn.disabled = false;
            btn.style.opacity = '1';
        } else {
            throw new Error(data.error || 'Erreur inconnue');
        }
    } catch (err) {
        status.innerHTML = `<p style="color: #ea4335;">Erreur : ${err.message}</p>`;
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1.2rem;">slideshow</span> Réessayer';
        btn.disabled = false;
        btn.style.opacity = '1';
    }
}
</script>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
