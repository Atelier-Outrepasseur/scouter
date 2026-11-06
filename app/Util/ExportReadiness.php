<?php

namespace App\Util;

/**
 * Avant un export Google Sheets / Slides : les analyses facultatives ont-elles
 * été lancées pour ce crawl ?
 *
 * Sans enrichissement (images, hreflang) ni analyse PageSpeed, les exports
 * affichent ces sections « Non mesuré » : la page Export prévient avant
 * l'export plutôt que de laisser croire que tout est réglé.
 */
class ExportReadiness
{
    /**
     * @return array{enrichment: bool, pagespeed: bool} true = analyse lancée (au moins une page analysée)
     */
    public static function forCrawl(\PDO $pdo, int $crawlId): array
    {
        return [
            'enrichment' => self::hasRows($pdo, 'page_enrichment', $crawlId),
            'pagespeed' => self::hasRows($pdo, 'page_pagespeed', $crawlId),
        ];
    }

    /** Table absente (enrichisseur jamais lancé sur ce serveur) = analyse non lancée. */
    private static function hasRows(\PDO $pdo, string $table, int $crawlId): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE crawl_id = :cid LIMIT 1");
            if (!$stmt || !$stmt->execute([':cid' => $crawlId])) {
                return false;
            }
            return $stmt->fetchColumn() !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }
}
