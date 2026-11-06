<?php
/**
 * PAGE : Export des données
 */

// Récupérer l'email du compte Google connecté POUR L'USER COURANT
$googleEmail = null;
try {
    $stmt = $pdo->prepare("
        SELECT email FROM google_tokens
        WHERE user_id = :uid
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $currentUserId]);
    $row = $stmt->fetch();
    if ($row) $googleEmail = $row->email;
} catch (Exception $e) {}

// Analyses facultatives lancées pour ce crawl ? Sinon, ces sections sortent « Non mesuré » dans les exports.
$readiness = \App\Util\ExportReadiness::forCrawl($pdo, (int)$crawlId);
?>


<h1 class="page-title">Export des données</h1>

<?php include __DIR__ . '/../components/google-reconnect.php'; ?>

<?php if (!$readiness['enrichment'] || !$readiness['pagespeed']): ?>
<div id="export-not-measured" style="max-width: 800px; margin-bottom: 1.5rem; padding: 1rem 1.25rem; border-radius: 8px;
     background: rgba(251, 140, 0, 0.08); border: 1px solid rgba(251, 140, 0, 0.45); color: var(--text-primary); font-size: 0.9rem;">
    <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; margin-bottom: 0.4rem;">
        <span class="material-symbols-outlined" style="color: #fb8c00;">warning</span>
        Avant d'exporter : certaines analyses n'ont pas été lancées pour ce crawl
    </div>
    <ul style="margin: 0 0 0 1.5rem; padding: 0;">
        <?php if (!$readiness['enrichment']): ?>
        <li><strong>Enrichissement non lancé</strong> : images et texte alternatif, hreflang et Open Graph sortiront
            « Non mesuré » dans le classeur et la présentation.
            <a href="?crawl=<?= (int)$crawlId ?>&page=images" style="color: var(--primary-color);">Lancer l'enrichissement</a></li>
        <?php endif; ?>
        <?php if (!$readiness['pagespeed']): ?>
        <li><strong>Analyse PageSpeed non lancée</strong> : la vitesse ressentie sortira « Non mesuré ».
            <a href="?crawl=<?= (int)$crawlId ?>&page=pagespeed" style="color: var(--primary-color);">Lancer l'analyse PageSpeed</a></li>
        <?php endif; ?>
    </ul>
    <p style="margin: 0.5rem 0 0; color: var(--text-secondary);">Vous pouvez exporter quand même ; relancez l'export une fois les analyses terminées.</p>
</div>
<?php endif; ?>

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
            <strong>Onglets générés :</strong> Index (synthèse et graphiques), Todo (problèmes classés P0, P1, P2),
            Code de réponse, Balises Titres, Balises Desc, Balises H1, Nombre de mots, Temps de réponse, Profondeur,
            Maillage interne, Données structurées, Images, Hreflang, PageSpeed, Non vérifiées (URL sans réponse du site)
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
                    Génère une présentation d'audit SEO de 10 diapositives, lisible par un client
                </p>
            </div>
        </div>

        <div style="background: var(--bg-secondary); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.85rem; color: var(--text-secondary);">
            <strong>Diapositives générées :</strong> Couverture, Synthèse, Plan d'action, Codes de réponse et liens cassés,
            Indexabilité, Titres, metas et H1, Contenu et temps de réponse, Maillage et profondeur, Images, Méthode et limites.
            <br>Les graphiques sont ceux du dernier export Google Sheets de ce crawl ; s'il n'y en a pas, un classeur est créé.
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
/*
 * Exports en arrière-plan : on lance une tâche, puis on suit son avancement.
 * Plus de requête qui reste ouverte pendant toute la création du document.
 */
const SG_URL = '<?= scouter_google_url() ?>';
const EXPORT_EMAIL = <?= json_encode($googleEmail ?? '') ?>;
const EXPORT_CRAWL_ID = <?= (int)$crawlId ?>;
const EXPORT_UI = {
    sheets: { btn: 'export-sheets-btn', status: 'export-sheets-status', icon: 'cloud_upload', label: 'Exporter vers Google Sheets', open: 'Ouvrir le Google Sheets', color: '#34a853' },
    slides: { btn: 'export-slides-btn', status: 'export-slides-status', icon: 'slideshow', label: 'Exporter vers Google Slides', open: 'Ouvrir la présentation', color: '#f59e0b' }
};

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/** Appel JSON robuste : une réponse non JSON (502, page d'erreur…) donne un message clair. */
async function sgFetch(path, options = {}) {
    let response;
    try {
        response = await fetch(SG_URL + path, { credentials: 'include', ...options });
    } catch (e) {
        throw new Error("Le service d'export est injoignable (" + e.message + ')');
    }
    const text = await response.text();
    let data = null;
    try { data = JSON.parse(text); } catch (e) { /* réponse non JSON */ }
    if (!response.ok) {
        const msg = data && data.error ? data.error : "Le service d'export a répondu HTTP " + response.status + (data ? '' : ' (réponse inattendue)');
        throw new Error(msg);
    }
    if (!data) throw new Error("Réponse inattendue du service d'export (HTTP " + response.status + ')');
    return data;
}

function setButton(kind, busy, text) {
    const ui = EXPORT_UI[kind];
    const btn = document.getElementById(ui.btn);
    if (!btn) return;
    btn.disabled = busy;
    btn.style.opacity = busy ? '0.6' : '1';
    btn.innerHTML = busy
        ? '<span class="material-symbols-outlined" style="font-size: 1.2rem; animation: spin 1s linear infinite;">sync</span> ' + escapeHtml(text || 'Export en cours...')
        : '<span class="material-symbols-outlined" style="font-size: 1.2rem;">' + ui.icon + '</span> ' + escapeHtml(text || ui.label);
}

function renderJob(kind, job) {
    const ui = EXPORT_UI[kind];
    const status = document.getElementById(ui.status);
    if (!status || !job) return;
    status.style.display = 'block';
    const warnings = (job.warnings || []).length
        ? '<ul style="margin: 0.5rem 0 0 1rem; color: #b45309; font-size: 0.85rem;">' + job.warnings.map(w => '<li>' + escapeHtml(w) + '</li>').join('') + '</ul>'
        : '';
    if (job.status === 'queued' || job.status === 'running') {
        status.innerHTML =
            '<p style="color: var(--text-secondary); margin-bottom: 0.4rem;">' + escapeHtml(job.step || 'En attente') + ' — ' + (job.progress || 0) + ' %</p>' +
            '<div style="height: 6px; background: var(--border-color); border-radius: 3px; overflow: hidden;">' +
            '<div style="height: 100%; width: ' + (job.progress || 0) + '%; background: ' + ui.color + '; transition: width .4s;"></div></div>' + warnings;
    } else if (job.status === 'done') {
        status.innerHTML =
            '<div style="padding: 1rem; background: rgba(52, 168, 83, 0.08); border: 1px solid rgba(52, 168, 83, 0.3); border-radius: 8px;">' +
            '<p style="color: ' + ui.color + '; font-weight: 600; margin-bottom: 0.5rem;">Export terminé</p>' +
            '<a href="' + escapeHtml(job.url) + '" target="_blank" rel="noopener" style="color: var(--primary-color); text-decoration: none; font-size: 0.95rem;">' +
            '<span class="material-symbols-outlined" style="font-size: 1rem; vertical-align: middle;">open_in_new</span> ' + ui.open + '</a>' + warnings + '</div>';
    } else {
        status.innerHTML =
            '<div style="padding: 1rem; background: rgba(234, 67, 53, 0.08); border: 1px solid rgba(234, 67, 53, 0.3); border-radius: 8px;">' +
            '<p style="color: #ea4335; font-weight: 600; margin-bottom: 0.3rem;">Export en échec</p>' +
            '<p style="color: var(--text-primary); font-size: 0.9rem;">' + escapeHtml(job.error || 'Erreur inconnue') + '</p>' + warnings + '</div>';
    }
}

async function followJob(kind, jobId) {
    setButton(kind, true);
    for (let errors = 0; ;) {
        try {
            const { job } = await sgFetch('/export/jobs/' + jobId);
            errors = 0;
            renderJob(kind, job);
            if (job.status === 'done' || job.status === 'failed') {
                setButton(kind, false, job.status === 'done' ? 'Exporter à nouveau' : 'Réessayer');
                return;
            }
        } catch (e) {
            // Coupure passagère : on réessaie, puis on affiche l'erreur
            if (++errors >= 5) {
                renderJob(kind, { status: 'failed', error: "Suivi de l'export impossible : " + e.message });
                setButton(kind, false, 'Réessayer');
                return;
            }
        }
        await new Promise(r => setTimeout(r, 2000));
    }
}

async function startExport(kind) {
    setButton(kind, true, 'Lancement...');
    renderJob(kind, { status: 'queued', step: 'Lancement de l\'export', progress: 0 });
    try {
        const { job } = await sgFetch('/export/' + kind, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: EXPORT_EMAIL, crawlId: EXPORT_CRAWL_ID })
        });
        renderJob(kind, job);
        await followJob(kind, job.id);
    } catch (e) {
        renderJob(kind, { status: 'failed', error: e.message });
        setButton(kind, false, 'Réessayer');
    }
}

function exportSheets() { return startExport('sheets'); }
function exportSlides() { return startExport('slides'); }

// Au chargement : afficher le dernier export de ce crawl, et reprendre le suivi s'il est en cours
(async () => {
    if (!EXPORT_EMAIL) return;
    try {
        const { jobs } = await sgFetch('/export/jobs?crawlId=' + EXPORT_CRAWL_ID);
        for (const kind of ['sheets', 'slides']) {
            const last = jobs.find(j => j.kind === kind);
            if (!last) continue;
            renderJob(kind, last);
            if (last.status === 'queued' || last.status === 'running') followJob(kind, last.id);
        }
    } catch (e) { /* page utilisable même si l'historique est indisponible */ }
})();
</script>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
