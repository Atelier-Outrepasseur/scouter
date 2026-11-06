<?php

/**
 * Helpers globaux pour l'authentification via le hub.
 * Chargé via composer autoload (files).
 */

if (!function_exists('hub_url')) {
    function hub_url(): string {
        return getenv('HUB_URL') ?: 'https://hub.outrepasseur.fr';
    }
}

if (!function_exists('hub_api_url')) {
    function hub_api_url(): string {
        return getenv('HUB_API_URL') ?: 'https://hub.outrepasseur.fr/api';
    }
}

if (!function_exists('verify_hub_session')) {
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
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) return null;
        
        $data = json_decode($response, true);
        return $data['user'] ?? null;
    }
}

if (!function_exists('current_public_url')) {
    /**
     * URL publique de la requête en cours (pour revenir ici après connexion au hub).
     * Derrière Traefik, le TLS est terminé en amont : PHP voit du http, on se fie
     * donc à X-Forwarded-Proto (seules les valeurs http/https sont acceptées)
     * et à X-Forwarded-Host.
     */
    function current_public_url(?string $path = null): string {
        $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($proto !== 'https' && $proto !== 'http') {
            $proto = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        }
        // Hôte d'origine transmis par le proxy (Traefik pose X-Forwarded-Host),
        // accepté seulement s'il a la forme d'un nom d'hôte[:port].
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
        if (!preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/', $host)) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        }
        return $proto . '://' . $host . ($path ?? ($_SERVER['REQUEST_URI'] ?? '/'));
    }
}
