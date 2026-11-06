<?php
require_once __DIR__ . "/../vendor/autoload.php";
// Connexion déléguée au hub : on l'envoie avec l'URL où revenir après connexion.
$hubUrl = getenv('HUB_URL') ?: 'https://hub.outrepasseur.fr';

// Chemin de retour : uniquement un chemin local (« /… », jamais « //… » ni « @… »),
// sinon l'URL construite ci-dessous pourrait désigner un autre site.
$redirect = $_GET['redirect'] ?? '/';
if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//') || str_contains($redirect, '\\')) {
    $redirect = '/';
}

$loginUrl = $hubUrl . '/login?redirect=' . urlencode(current_public_url($redirect));
header('Location: ' . $loginUrl);
exit;
