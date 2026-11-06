<?php

namespace App\Core;

/**
 * Exception levée quand le site bloque durablement le crawler malgré les
 * ralentissements et les pauses. Le crawl s'arrête proprement (statut
 * « stopped », reprise possible) ; les URLs non vérifiées restent à crawler.
 */
class CrawlBlockedException extends \RuntimeException
{
}
