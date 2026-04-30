<?php
/**
 * Fichier d'initialisation - Auth déléguée au hub seo-hub
 * Usage: require_once(__DIR__ . '/init.php');
 */

require_once(__DIR__ . "/../vendor/autoload.php");
require_once(__DIR__ . '/config/i18n.php');

/**
 * Retourne l'URL de base du service scouter-google.
 */
function scouter_google_url(): string {
    $url = getenv('SCOUTER_GOOGLE_URL');
    return $url !== false ? $url : 'http://localhost:3001';
}

/**
 * Retourne l'URL du hub d'authentification.
 */
function hub_url(): string {
    return getenv('HUB_URL') ?: 'https://hub.outrepasseur.fr';
}

/**
 * Retourne l'URL interne du backend hub (pour vérifier les sessions).
 */
function hub_api_url(): string {
    return getenv('HUB_API_URL') ?: 'http://hub-backend:3002';
}

/**
 * Vérifie le cookie hub_session auprès du backend hub.
 * Retourne les infos user ou null si pas authentifié.
 */
function verify_hub_session(): ?array {
    $sessionId = $_COOKIE['hub_session'] ?? null;
    if (!$sessionId) return null;
    
    $ch = curl_init(hub_api_url() . '/auth/verify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Cookie: hub_session=' . $sessionId,
        ],
        CURLOPT_TIMEOUT => 5,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) return null;
    
    $data = json_decode($response, true);
    return $data['user'] ?? null;
}

/**
 * Synchronise un user du hub avec la table users de Scouter.
 * Crée ou met à jour, retourne l'ID dans la BDD Scouter.
 */
function sync_hub_user_to_scouter(array $hubUser): int {
    $pdo = new PDO(getenv('DATABASE_URL'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $email = $hubUser['email'];
    $role = $hubUser['role'];
    
    // Vérifier si l'user existe déjà
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        // Update : synchroniser le rôle
        $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
        $stmt->execute(['role' => $role, 'id' => $row['id']]);
        return (int)$row['id'];
    }
    
    // Insert : password aléatoire (jamais utilisé car auth via hub)
    $randomPassword = bin2hex(random_bytes(32));
    $hashedPassword = password_hash($randomPassword, PASSWORD_BCRYPT);
    
    $stmt = $pdo->prepare(
        "INSERT INTO users (email, password_hash, role) 
         VALUES (:email, :password_hash, :role) 
         RETURNING id"
    );
    $stmt->execute([
        'email' => $email,
        'password_hash' => $hashedPassword,
        'role' => $role,
    ]);
    
    return (int)$stmt->fetchColumn();
}

// Pages publiques (toujours accessibles)
$currentFile = basename($_SERVER['PHP_SELF']);
$publicPages = ['login.php'];

if (!in_array($currentFile, $publicPages)) {
    $hubUser = verify_hub_session();
    
    if (!$hubUser) {
        // Redirect vers le hub
        $currentUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $loginUrl = hub_url() . '/login?redirect=' . urlencode($currentUrl);
        header('Location: ' . $loginUrl);
        exit;
    }
    
    // Synchroniser avec la table users de Scouter
    $scouterUserId = sync_hub_user_to_scouter($hubUser);
    
    // Variables globales utilisées par les pages
    $currentEmail = $hubUser['email'];
    $currentUserId = $scouterUserId;
    $isAdmin = $hubUser['role'] === 'admin';
    $isViewer = $hubUser['role'] === 'viewer';
    $canCreate = !$isViewer;
    
    // Hub URL pour les liens "logout" / "retour au hub"
    $hubUrlPublic = hub_url();
}