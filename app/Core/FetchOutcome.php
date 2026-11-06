<?php

namespace App\Core;

/**
 * Classe le résultat d'une requête de crawl.
 *
 * Distingue une VRAIE réponse de page (200, 301, 404, 410, 500…) des cas où le
 * site n'a pas répondu ou a refusé de répondre au crawler (délai dépassé,
 * connexion refusée ou coupée, 429, 503, page de défi anti-robot). Ces derniers
 * ne disent rien de la page : ils ne doivent jamais être enregistrés comme une
 * erreur de page, mais réessayés plus tard (et ralentir le crawl).
 *
 * Valeurs de $kind :
 *  - KIND_RESPONSE  : réponse HTTP exploitable, à enregistrer telle quelle
 *  - KIND_TRANSIENT : erreur serveur passagère (500, 502, 504) : réessayer,
 *                     puis enregistrer le code réel si elle persiste
 *  - KIND_BLOCKED   : le site limite ou bloque le crawler (429, 503, défi anti-robot)
 *  - KIND_TIMEOUT   : délai dépassé (connexion ou réponse)
 *  - KIND_NETWORK   : connexion refusée, coupée, réponse vide, erreur TLS ou DNS
 *  - KIND_RENDERER  : échec du service de rendu JavaScript (problème interne)
 */
final class FetchOutcome
{
    public const KIND_RESPONSE  = 'response';
    public const KIND_TRANSIENT = 'transient';
    public const KIND_BLOCKED   = 'blocked';
    public const KIND_TIMEOUT   = 'timeout';
    public const KIND_NETWORK   = 'network';
    public const KIND_RENDERER  = 'renderer';

    /** Codes cURL : délai dépassé */
    private const ERRNO_TIMEOUT = [28];
    /** Codes cURL : problème de connexion (pas une réponse de la page) */
    private const ERRNO_NETWORK = [
        5,  // proxy non résolu
        6,  // hôte non résolu (DNS)
        7,  // connexion impossible / refusée
        16, // erreur HTTP/2
        35, // échec de la négociation TLS
        52, // réponse vide
        55, // échec d'envoi
        56, // échec de réception (connexion réinitialisée)
        92, // flux HTTP/2 fermé
    ];

    /** Titres des pages de défi anti-robot les plus courantes */
    private const CHALLENGE_MARKERS = [
        'Attention Required! | Cloudflare',
        'Just a moment...',
        'cf-browser-verification',
        'cf_chl_opt',
        'challenge-platform',
        'DDoS protection by',
        'Request unsuccessful. Incapsula',
        'Access Denied - Sucuri',
    ];

    public function __construct(
        public readonly string $kind,
        public readonly int $httpCode,
        public readonly int $errno = 0,
        public readonly string $detail = ''
    ) {
    }

    /**
     * Classe un résultat brut (code HTTP, erreur cURL, début du corps).
     */
    public static function classify(int $httpCode, int $errno = 0, string $body = '', string $error = ''): self
    {
        if ($errno !== 0 || $httpCode === 0) {
            if (in_array($errno, self::ERRNO_TIMEOUT, true) || stripos($error, 'timed out') !== false) {
                return new self(self::KIND_TIMEOUT, 0, $errno, $error ?: 'délai dépassé');
            }
            if ($errno !== 0 && !in_array($errno, self::ERRNO_NETWORK, true) && $httpCode > 0) {
                // Erreur cURL tardive alors qu'un code HTTP a été reçu (ex. corps tronqué) :
                // la page a bien répondu, on garde son code.
                return new self(self::KIND_RESPONSE, $httpCode, $errno, $error);
            }
            return new self(self::KIND_NETWORK, 0, $errno, $error ?: 'aucune réponse');
        }

        if ($httpCode === 429) {
            return new self(self::KIND_BLOCKED, 429, 0, 'trop de requêtes (429)');
        }
        if (($httpCode === 403 || $httpCode === 503) && self::isChallenge($body)) {
            return new self(self::KIND_BLOCKED, $httpCode, 0, 'page de défi anti-robot');
        }
        if ($httpCode === 503) {
            return new self(self::KIND_BLOCKED, 503, 0, 'service indisponible (503)');
        }
        if (in_array($httpCode, [500, 502, 504], true)) {
            return new self(self::KIND_TRANSIENT, $httpCode, 0, "erreur serveur ($httpCode)");
        }
        return new self(self::KIND_RESPONSE, $httpCode);
    }

    public static function renderer(string $error): self
    {
        if (stripos($error, 'timeout') !== false || stripos($error, 'timed out') !== false) {
            return new self(self::KIND_TIMEOUT, 0, 0, 'rendu JS : ' . $error);
        }
        return new self(self::KIND_RENDERER, 0, 0, 'rendu JS : ' . $error);
    }

    public static function isChallenge(string $body): bool
    {
        if ($body === '') {
            return false;
        }
        $head = substr($body, 0, 20000);
        foreach (self::CHALLENGE_MARKERS as $marker) {
            if (stripos($head, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Réponse de page à enregistrer (y compris 4xx et 5xx réels). */
    public function isPageResponse(): bool
    {
        return $this->kind === self::KIND_RESPONSE;
    }

    /** Le site n'a pas (vraiment) répondu : signal de blocage pour le régulateur. */
    public function isBlockingSignal(): bool
    {
        return in_array($this->kind, [self::KIND_BLOCKED, self::KIND_TIMEOUT, self::KIND_NETWORK], true);
    }

    /** Doit être réessayé plus tard plutôt qu'enregistré tout de suite. */
    public function isRetryable(): bool
    {
        return $this->kind !== self::KIND_RESPONSE;
    }

    /**
     * Valeur de pages.fetch_status quand la page n'a pas pu être vérifiée
     * (jamais un code d'erreur HTTP).
     */
    public function unverifiedStatus(): string
    {
        return match ($this->kind) {
            self::KIND_BLOCKED  => 'blocked',
            self::KIND_TIMEOUT  => 'timeout',
            self::KIND_RENDERER => 'renderer',
            default             => 'network',
        };
    }

    public function label(): string
    {
        return $this->kind . ($this->detail !== '' ? " ({$this->detail})" : '');
    }
}
