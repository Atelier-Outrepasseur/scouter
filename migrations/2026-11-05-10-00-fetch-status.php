<?php
/**
 * Migration : distinguer une page « non vérifiée » (le site n'a pas répondu :
 * blocage, délai dépassé, réseau, rendu JS) d'une vraie erreur de page.
 *
 * - pages.fetch_status : NULL = le site a répondu (code HTTP réel) ;
 *   'blocked' | 'timeout' | 'network' | 'renderer' = non vérifiée ;
 *   'unverified' = ancien crawl, cause inconnue (code 0 sans réponse).
 * - crawls.unverified : nombre d'URLs non vérifiées du crawl.
 *
 * Les anciens « code 0 » crawlés (aucune réponse du site) sont requalifiés en
 * 'unverified' : ils ne comptent plus comme des erreurs.
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("ALTER TABLE pages ADD COLUMN IF NOT EXISTS fetch_status VARCHAR(20) DEFAULT NULL");
    echo "(pages.fetch_status) ";
    $pdo->exec("ALTER TABLE crawls ADD COLUMN IF NOT EXISTS unverified INTEGER DEFAULT 0");
    echo "(crawls.unverified) ";

    $n = $pdo->exec("UPDATE pages SET fetch_status = 'unverified'
                     WHERE crawled = true AND code = 0 AND fetch_status IS NULL");
    echo "($n ancienne(s) URL(s) code 0 requalifiée(s) en non vérifiées) ";

    $pdo->exec("UPDATE crawls c SET
                  unverified = s.unverified,
                  crawled = c.crawled - s.unverified
                FROM (SELECT crawl_id, COUNT(*) AS unverified FROM pages
                      WHERE fetch_status IS NOT NULL GROUP BY crawl_id) s
                WHERE s.crawl_id = c.id AND COALESCE(c.unverified, 0) = 0");
    echo "(statistiques des crawls mises à jour) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
