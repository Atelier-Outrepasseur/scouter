<?php
/**
 * Migration : couche de lecture commune des données de crawl.
 *
 * L'enricher (PHP) et les exports Google Sheets / Slides (Node) lisent les
 * données UNIQUEMENT à travers ces vues. Les définitions (« page interne
 * crawlée », « vérifiée », famille de code, HTML exploitable…) n'existent donc
 * qu'à un seul endroit, et un futur changement de stockage ne touchera qu'elles.
 *
 * - crawl_data_pages   : pages internes crawlées, avec `verified` (le site a
 *                        répondu) et `code_family` ('2xx'…'5xx', 'non_verifiee').
 * - crawl_data_html    : HTML des pages vérifiées en 200 (pour l'enricher).
 * - crawl_data_summary : comptes par crawl (vérifiées, non vérifiées par cause,
 *                        familles de codes, indexables, temps de réponse moyen).
 *
 * Contrat : les colonnes existantes ne sont jamais renommées ni supprimées ;
 * on ne fait qu'en ajouter (CREATE OR REPLACE VIEW l'impose de toute façon).
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("
        CREATE OR REPLACE VIEW crawl_data_pages AS
        SELECT
            p.crawl_id, p.id, p.url, p.domain, p.depth, p.code, p.response_time,
            p.inlinks, p.outlinks, p.pri, p.content_type, p.redirect_to,
            p.compliant, p.noindex, p.nofollow, p.canonical, p.canonical_value, p.blocked,
            p.title, p.title_status, p.h1, p.h1_status, p.h1_multiple, p.headings_missing,
            p.metadesc, p.metadesc_status, p.word_count, p.simhash, p.schemas,
            p.is_html, p.extracts, p.cat_id, p.date, p.fetch_status,
            (p.fetch_status IS NULL) AS verified,
            CASE
                WHEN p.fetch_status IS NOT NULL THEN 'non_verifiee'
                WHEN p.code BETWEEN 200 AND 299 THEN '2xx'
                WHEN p.code BETWEEN 300 AND 399 THEN '3xx'
                WHEN p.code BETWEEN 400 AND 499 THEN '4xx'
                WHEN p.code BETWEEN 500 AND 599 THEN '5xx'
                ELSE 'autre'
            END AS code_family
        FROM pages p
        WHERE p.crawled = true AND p.external = false
    ");
    echo "(vue crawl_data_pages) ";

    $pdo->exec("
        CREATE OR REPLACE VIEW crawl_data_html AS
        SELECT p.crawl_id, p.id, p.url, h.html
        FROM crawl_data_pages p
        JOIN html h ON h.crawl_id = p.crawl_id AND h.id = p.id
        WHERE p.verified AND p.code = 200 AND p.is_html = true AND h.html <> ''
    ");
    echo "(vue crawl_data_html) ";

    $pdo->exec("
        CREATE OR REPLACE VIEW crawl_data_summary AS
        SELECT
            crawl_id,
            COUNT(*)                                              AS crawled_total,
            COUNT(*) FILTER (WHERE verified)                      AS verified,
            COUNT(*) FILTER (WHERE NOT verified)                  AS unverified,
            COUNT(*) FILTER (WHERE fetch_status = 'blocked')      AS unverified_blocked,
            COUNT(*) FILTER (WHERE fetch_status = 'timeout')      AS unverified_timeout,
            COUNT(*) FILTER (WHERE fetch_status = 'network')      AS unverified_network,
            COUNT(*) FILTER (WHERE fetch_status = 'renderer')     AS unverified_renderer,
            COUNT(*) FILTER (WHERE fetch_status = 'unverified')   AS unverified_legacy,
            COUNT(*) FILTER (WHERE code = 200 AND verified)       AS code_200,
            COUNT(*) FILTER (WHERE code_family = '2xx')           AS code_2xx,
            COUNT(*) FILTER (WHERE code_family = '3xx')           AS code_3xx,
            COUNT(*) FILTER (WHERE code_family = '4xx')           AS code_4xx,
            COUNT(*) FILTER (WHERE code_family = '5xx')           AS code_5xx,
            COUNT(*) FILTER (WHERE compliant)                     AS compliant,
            AVG(response_time) FILTER (WHERE code = 200 AND verified AND response_time > 0) AS avg_response_time
        FROM crawl_data_pages
        GROUP BY crawl_id
    ");
    echo "(vue crawl_data_summary) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
