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