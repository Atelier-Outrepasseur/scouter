<?php
/**
 * Bandeau « Reconnecter Google » (pages Export et Search Console).
 *
 * Affiché quand un compte Google est ENREGISTRÉ pour l'utilisateur mais que
 * son autorisation a expiré ou a été révoquée (Google répond « invalid_grant »).
 *  - Côté serveur : état mémorisé par scouter-google (google_tokens.auth_status).
 *  - Côté navigateur : vérification légère via /auth/google/status (au plus
 *    toutes les 10 min par compte), puis de nouveau quand on revient sur
 *    l'onglet après s'être reconnecté (le bandeau disparaît alors).
 * Le bouton mène à /auth/google (même parcours que « Connecter Google »).
 *
 * Variables attendues : $pdo (PostgreSQL), $currentUserId.
 */
$googleReconnectAccounts = [];
$googleRevokedEmails = [];
try {
    $stmtGr = $pdo->prepare("SELECT email, auth_status FROM google_tokens WHERE user_id = :uid ORDER BY updated_at DESC");
    $stmtGr->execute([':uid' => $currentUserId]);
    foreach ($stmtGr->fetchAll(PDO::FETCH_OBJ) as $rowGr) {
        $googleReconnectAccounts[] = $rowGr->email;
        if (($rowGr->auth_status ?? null) === 'revoked') {
            $googleRevokedEmails[] = $rowGr->email;
        }
    }
} catch (Exception $e) {
    // Colonne absente (scouter-google pas encore à jour) : on s'appuie sur la vérification navigateur.
    try {
        $stmtGr = $pdo->prepare("SELECT email FROM google_tokens WHERE user_id = :uid");
        $stmtGr->execute([':uid' => $currentUserId]);
        $googleReconnectAccounts = $stmtGr->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e2) {}
}

if (!empty($googleReconnectAccounts)):
?>
<div id="google-reconnect" role="alert"
     style="display: <?= empty($googleRevokedEmails) ? 'none' : 'flex' ?>; align-items: center; gap: 1rem; flex-wrap: wrap;
            margin: 0 0 1.5rem; padding: 1rem 1.25rem; max-width: 800px;
            background: rgba(234, 67, 53, 0.08); border: 1px solid rgba(234, 67, 53, 0.35); border-radius: 10px;">
    <span class="material-symbols-outlined" style="font-size: 1.8rem; color: #ea4335;">link_off</span>
    <div style="flex: 1; min-width: 240px;">
        <strong style="color: var(--text-primary);">Autorisation Google expirée ou révoquée</strong>
        <p id="google-reconnect-text" style="margin: 0.25rem 0 0; color: var(--text-secondary); font-size: 0.9rem;">
            <?php if (!empty($googleRevokedEmails)): ?>
                Le compte <strong><?= htmlspecialchars(implode(', ', $googleRevokedEmails)) ?></strong> doit être reconnecté pour exporter et synchroniser les données Google.
            <?php else: ?>
                Le compte Google doit être reconnecté pour exporter et synchroniser les données Google.
            <?php endif; ?>
        </p>
    </div>
    <a id="google-reconnect-btn" href="<?= scouter_google_url() ?>/auth/google" target="_blank" rel="noopener"
       style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.65rem 1.25rem;
              background: #ea4335; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600;">
        <span class="material-symbols-outlined" style="font-size: 1.1rem;">sync_lock</span>
        Reconnecter Google
    </a>
</div>
<script>
(function () {
    const box = document.getElementById('google-reconnect');
    const text = document.getElementById('google-reconnect-text');
    if (!box) return;
    let checking = false;
    async function check(force) {
        if (checking) return;
        checking = true;
        try {
            const r = await fetch('<?= scouter_google_url() ?>/auth/google/status' + (force ? '?force=1' : ''), { credentials: 'include' });
            if (!r.ok) return;
            const data = await r.json();
            const revoked = (data.accounts || []).filter(a => a.status === 'revoked').map(a => a.email);
            if (revoked.length) {
                text.textContent = 'Le compte ' + revoked.join(', ') + ' doit être reconnecté pour exporter et synchroniser les données Google.';
                box.style.display = 'flex';
            } else if ((data.accounts || []).every(a => a.status === 'ok')) {
                box.style.display = 'none';
            }
        } catch (e) { /* service injoignable : on garde l'état connu */ }
        finally { checking = false; }
    }
    check(false);
    // Après la reconnexion (dans un autre onglet), on revérifie au retour sur la page.
    let reconnectClicked = false;
    document.getElementById('google-reconnect-btn').addEventListener('click', () => { reconnectClicked = true; });
    window.addEventListener('focus', () => { if (reconnectClicked || box.style.display !== 'none') check(true); });
})();
</script>
<?php endif; ?>
