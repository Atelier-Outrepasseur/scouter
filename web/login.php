<?php
$hubUrl = getenv('HUB_URL') ?: 'https://hub.outrepasseur.fr';
$redirect = $_GET['redirect'] ?? '/';
$currentHost = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$loginUrl = $hubUrl . '/login?redirect=' . urlencode($currentHost . $redirect);
header('Location: ' . $loginUrl);
exit;