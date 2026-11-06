<?php

namespace App\Core;

use RollingCurl\RollingCurl;
use RollingCurl\Request;
use App\Util\HtmlParser;
use App\Util\JsRenderer;
use App\Database\CrawlDatabase;

/**
 * Crawler d'une profondeur donnée.
 *
 * Toutes les requêtes passent par une seule boucle régulée (CrawlGovernor) :
 *  - débit et parallélisme fixés par la vitesse choisie (MAX_CONCURRENT_CURL
 *    n'est plus qu'un plafond) ;
 *  - chaque résultat est classé (FetchOutcome) : une vraie réponse de page est
 *    enregistrée ; un blocage, un délai dépassé ou une erreur réseau ne l'est
 *    jamais comme une erreur de page : l'URL est reportée et réessayée plus
 *    tard, avec le même débit, jamais en rafale ;
 *  - si le site bloque, le crawl ralentit, fait une pause puis reprend ;
 *  - une URL toujours injoignable après plusieurs essais est marquée « non
 *    vérifiée » (pages.fetch_status), pas en erreur.
 *
 * @package    Scouter
 * @subpackage Crawler
 * @see Crawler Pour l'orchestration globale
 * @see CrawlGovernor, FetchOutcome
 */
class DepthCrawler
{
    /** Essais maximum pour une URL qui ne répond pas (blocage, délai, réseau) */
    public const MAX_ATTEMPTS = 5;
    /** Essais maximum pour une erreur serveur passagère avant d'enregistrer le code réel */
    public const MAX_TRANSIENT_ATTEMPTS = 3;
    private const BASE_DELAY = 2;
    private const JITTER_PERCENT = 20;

    private CrawlDatabase $crawlDb;
    private $depth;
    private $urls;
    private $domains;
    private $config;
    private $crawlMode;
    private array $curlOptions;
    private CrawlGovernor $governor;
    private int $totalForDepth = 0;
    private int $processedOffset = 0;
    private int $update = 0;
    private int $unverified = 0;
    private int $recovered = 0;
    private float $startedAt = 0.0;
    private float $lastStopCheck = 0.0;

    static $lastStatsUpdate = 0;

    public function __construct(CrawlDatabase $crawlDb, $domains, $config, ?CrawlGovernor $governor = null)
    {
        $this->crawlDb = $crawlDb;
        $this->domains = $domains;
        $this->config = $config;
        $this->crawlMode = $config['crawl_mode'] ?? 'classic';
        $this->governor = $governor ?? CrawlGovernor::forCrawl($crawlDb->getCrawlId(), $config);

        $httpHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];
        if (isset($this->config['customHeaders']) && is_array($this->config['customHeaders'])) {
            foreach ($this->config['customHeaders'] as $name => $value) {
                $httpHeaders[] = "$name: $value";
            }
        }
        $userAgent = $this->config['user-agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36';

        $this->curlOptions = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $userAgent,
            // Délais alignés sur les crawlers de référence : 5 s faisaient passer
            // pour « Timeout » toute page un peu lente.
            CURLOPT_CONNECTTIMEOUT => (int)(getenv('CRAWL_CONNECT_TIMEOUT') ?: 10),
            CURLOPT_TIMEOUT => (int)(getenv('CRAWL_TIMEOUT') ?: 20),
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 60,
            CURLOPT_TCP_KEEPINTVL => 30,
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_TCP_NODELAY => true,
        ];
        if (isset($this->config['httpAuth']['enabled']) && $this->config['httpAuth']['enabled'] === true) {
            if (!empty($this->config['httpAuth']['username']) && !empty($this->config['httpAuth']['password'])) {
                $this->curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                $this->curlOptions[CURLOPT_USERPWD] = $this->config['httpAuth']['username'] . ':' . $this->config['httpAuth']['password'];
            }
        }
    }

    public function getGovernor(): CrawlGovernor
    {
        return $this->governor;
    }

    public function getNextUrls(int $limit = 0, int $maxDepth = -1)
    {
        $respectRobots = $this->config['respect']['robots'] ?? true;
        return $this->crawlDb->getUrlsToCrawl($respectRobots, $limit, $maxDepth);
    }

    public function countRemainingUrls(int $maxDepth = -1): int
    {
        $respectRobots = $this->config['respect']['robots'] ?? true;
        return $this->crawlDb->countUrlsToCrawl($respectRobots, $maxDepth);
    }

    public function run(array $options)
    {
        $this->depth = $options['depth'];
        $this->urls = $options['urls'];
        $this->totalForDepth = $options['totalForDepth'] ?? count($this->urls);
        $this->processedOffset = $options['processedOffset'] ?? 0;
        $this->startedAt = microtime(true);

        $urls = [];
        foreach ($this->urls as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            if (HtmlParser::regexMatch("#^https?://[^/]+$#", $url) == true) {
                $url .= '/';
            }
            $urls[] = $url;
        }

        if ($this->crawlMode === 'javascript') {
            $this->loop($urls, fn(array $batch) => $this->fetchWithRenderer($batch), $this->rendererBatchSize());
        } else {
            $this->loop($urls, fn(array $batch) => $this->fetchWithCurl($batch), null);
        }

        $this->crawlDb->updateCrawlStats();
        self::$lastStatsUpdate = time();
        $this->summary();
    }

    private function checkStopSignal(): void
    {
        $now = microtime(true);
        if ($now - $this->lastStopCheck < 3) {
            return;
        }
        $this->lastStopCheck = $now;
        $status = $this->crawlDb->getCrawlStatus();
        if ($status === 'stopping' || $status === 'stopped' || $status === 'failed') {
            throw new \Exception("Crawl stop signal received");
        }
    }

    /**
     * Boucle commune : lots régulés, reports, pauses.
     *
     * @param string[] $urls
     * @param callable(string[]): array<int, array{0:string,1:mixed,2:FetchOutcome}> $fetch
     */
    private function loop(array $urls, callable $fetch, ?int $fixedBatch): void
    {
        $retryEnabled = $this->config['retry_failed_urls'] ?? true;
        $queue = $urls;
        /** @var array<string, array{attempts:int, at:float, outcome:FetchOutcome, request:mixed}> $deferred */
        $deferred = [];

        while (!empty($queue) || !empty($deferred)) {
            $this->checkStopSignal();

            if ($this->governor->shouldPause()) {
                if ($this->governor->shouldGiveUp()) {
                    throw new CrawlBlockedException(sprintf(
                        'Le site bloque le crawler depuis trop longtemps (%d s de pause cumulée) : crawl arrêté, %d URL(s) restent à vérifier (reprise possible).',
                        $this->governor->stats()['paused_seconds'], count($deferred) + count($queue)
                    ));
                }
                $this->governor->pause(fn() => $this->checkStopSignal());
                // Après une pause, les URLs reportées sont prêtes tout de suite, et
                // leurs essais repartent à zéro : leurs échecs venaient du blocage du
                // site, pas des pages (un site qui bloque met le crawl en pause, il
                // ne doit pas faire passer ses pages en « non vérifiées »).
                foreach ($deferred as &$d) {
                    $d['at'] = 0.0;
                    $d['attempts'] = 0;
                }
                unset($d);
            }

            $size = $fixedBatch ?? $this->governor->batchSize();
            $now = microtime(true);
            $batch = [];
            foreach ($deferred as $u => $d) {
                if (count($batch) >= $size) {
                    break;
                }
                if ($d['at'] <= $now) {
                    $batch[] = $u;
                }
            }
            while (count($batch) < $size && !empty($queue)) {
                $batch[] = array_shift($queue);
            }

            if (empty($batch)) {
                // Seules restent des URLs reportées pas encore « mûres »
                $next = min(array_column($deferred, 'at'));
                usleep((int)(max(0.2, min(5.0, $next - $now)) * 1e6));
                continue;
            }

            $t0 = microtime(true);
            foreach ($fetch($batch) as [$url, $request, $outcome]) {
                $this->governor->record($outcome);
                $attempts = ($deferred[$url]['attempts'] ?? 0) + 1;

                if ($outcome->isPageResponse()) {
                    if (isset($deferred[$url])) {
                        $this->recovered++;
                    }
                    unset($deferred[$url]);
                    $this->storePage($request);
                    continue;
                }

                $isTransient = $outcome->kind === FetchOutcome::KIND_TRANSIENT;
                $limit = $isTransient ? self::MAX_TRANSIENT_ATTEMPTS : self::MAX_ATTEMPTS;
                if ($retryEnabled && $attempts < $limit) {
                    $deferred[$url] = [
                        'attempts' => $attempts,
                        'at' => microtime(true) + $this->backoff($attempts),
                        'outcome' => $outcome,
                        'request' => $request,
                    ];
                    continue;
                }

                unset($deferred[$url]);
                if ($isTransient && $request !== null) {
                    // Erreur serveur persistante : c'est un vrai code de la page.
                    $this->storePage($request);
                } else {
                    $this->storeUnverified($url, $outcome);
                }
            }

            $this->progress();

            $elapsed = microtime(true) - $t0;
            $minDuration = $this->governor->minBatchDuration(count($batch));
            if ($elapsed < $minDuration) {
                usleep((int)(($minDuration - $elapsed) * 1e6));
            }
        }
    }

    private function backoff(int $attempt): float
    {
        $base = self::BASE_DELAY * (2 ** ($attempt - 1)); // 2, 4, 8, 16 s
        $jitter = $base * (mt_rand(-self::JITTER_PERCENT, self::JITTER_PERCENT) / 100);
        return $base + $jitter;
    }

    /**
     * Récupère un lot en HTTP direct, avec le parallélisme courant du régulateur.
     */
    private function fetchWithCurl(array $batch): array
    {
        // RollingCurl exige une limite >= 2 : à 1 connexion (plancher du
        // régulateur), on envoie les URLs une par une, chacune seule dans son lot.
        if ($this->governor->concurrency() < 2 && count($batch) > 1) {
            $results = [];
            foreach ($batch as $url) {
                array_push($results, ...$this->fetchWithCurl([$url]));
            }
            return $results;
        }

        $results = [];
        $rc = new RollingCurl();
        $rc->setOptions($this->curlOptions);
        foreach ($batch as $url) {
            $rc->get($url);
        }
        $rc->setCallback(function (Request $request, RollingCurl $rollingCurl) use (&$results) {
            $info = $request->getResponseInfo();
            $outcome = FetchOutcome::classify(
                (int)($info['http_code'] ?? 0),
                (int)$request->getResponseErrno(),
                (string)$request->getResponseText(),
                (string)$request->getResponseError()
            );
            $results[] = [$request->getUrl(), $request, $outcome];
        })
            ->setSimultaneousLimit(max(2, min($this->governor->concurrency(), count($batch))))
            ->execute();
        return $results;
    }

    private function rendererUrls(): array
    {
        $env = getenv('RENDERER_URLS') ?: getenv('RENDERER_URL') ?: 'http://renderer:3000';
        return array_values(array_filter(array_map('trim', explode(',', $env))));
    }

    private function rendererBatchSize(): int
    {
        return count($this->rendererUrls()) * max(1, min(20, $this->governor->concurrency()));
    }

    /**
     * Récupère un lot via le service de rendu JavaScript. Un échec du renderer
     * n'est plus transformé en « 500 » de la page, ni ignoré en silence : les
     * URLs concernées sont reportées.
     */
    private function fetchWithRenderer(array $batch): array
    {
        $headers = ['User-Agent' => $this->config['user-agent'] ?? 'Scouter/0.3'];
        if (isset($this->config['customHeaders']) && is_array($this->config['customHeaders'])) {
            foreach ($this->config['customHeaders'] as $name => $value) {
                $headers[$name] = $value;
            }
        }
        if (isset($this->config['httpAuth']['enabled']) && $this->config['httpAuth']['enabled'] === true
            && !empty($this->config['httpAuth']['username']) && !empty($this->config['httpAuth']['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->config['httpAuth']['username'] . ':' . $this->config['httpAuth']['password']);
        }

        $renderers = $this->rendererUrls();
        $perRenderer = max(1, (int)ceil(count($batch) / count($renderers)));
        $chunks = array_chunk($batch, $perRenderer);

        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunks as $i => $chunkUrls) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $renderers[$i % count($renderers)] . '/render-batch',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['urls' => $chunkUrls, 'headers' => $headers]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = [$ch, $chunkUrls];
        }
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        $results = [];
        foreach ($handles as [$ch, $chunkUrls]) {
            $data = json_decode((string)curl_multi_getcontent($ch), true);
            $err = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $seen = [];
            foreach (($data['results'] ?? []) as $result) {
                $url = $result['url'] ?? '';
                if ($url === '') {
                    continue;
                }
                $seen[$url] = true;
                if (!empty($result['success'])) {
                    $code = (int)($result['httpCode'] ?? 200);
                    $html = $result['html'] ?? '';
                    $time = (float)($result['responseTime'] ?? 0);
                    $finalUrl = $result['finalUrl'] ?? '';
                    if (in_array($code, [301, 302, 303, 307, 308], true) && $finalUrl !== '') {
                        $request = new JsRequest($url, $html, $time, $code, '', $finalUrl);
                    } elseif (in_array($code, [200, 304], true) && !empty($result['jsRedirect']) && $finalUrl !== '') {
                        $request = new JsRequest($url, $html, $time, 311, '', $finalUrl);
                    } else {
                        $request = new JsRequest($url, $html, $time, $code);
                    }
                    $results[] = [$url, $request, FetchOutcome::classify($code, 0, $html)];
                } else {
                    $results[] = [$url, null, FetchOutcome::renderer((string)($result['error'] ?? 'erreur du renderer'))];
                }
            }
            // URLs absentes de la réponse (renderer en échec ou réponse invalide) : reportées
            foreach ($chunkUrls as $url) {
                if (!isset($seen[$url])) {
                    $results[] = [$url, null, FetchOutcome::renderer($err ?: 'réponse invalide du renderer')];
                }
            }
        }
        curl_multi_close($mh);
        return $results;
    }

    private function storePage($request): void
    {
        $pageCrawler = new PageCrawler($this->crawlDb, $this->depth, $this->domains, $this->config);
        $pageCrawler->run($request);
        $this->update++;
    }

    private function storeUnverified(string $url, FetchOutcome $outcome): void
    {
        PageCrawler::storeUnverified($this->crawlDb, $url, $outcome);
        $this->update++;
        $this->unverified++;
    }

    private function progress(): void
    {
        $globalDone = min($this->processedOffset + $this->update, $this->totalForDepth);
        $elapsed = microtime(true) - $this->startedAt;
        $speed = $elapsed > 0.5 ? round($this->update / $elapsed, 2) : 0;
        echo "\r \033[32m Depth " . $this->depth . " : \033[0m" . $speed . " URLs/sec \033[36m(" . $globalDone . "/" . $this->totalForDepth . ")\033[0m"
            . ($this->unverified > 0 ? " \033[33m" . $this->unverified . " non vérifiée(s)\033[0m" : '')
            . "                             ";
        flush();
        if (ob_get_level() > 0) {
            ob_flush();
        }
        if (time() - self::$lastStatsUpdate >= 10) {
            self::$lastStatsUpdate = time();
            $this->crawlDb->updateCrawlStats();
        }
    }

    private function summary(): void
    {
        $s = $this->governor->stats();
        $parts = [];
        if ($this->recovered > 0) {
            $parts[] = $this->recovered . ' URL(s) récupérée(s) après un nouvel essai';
        }
        if ($this->unverified > 0) {
            $parts[] = $this->unverified . ' URL(s) non vérifiée(s) (le site n\'a pas répondu ; ce ne sont pas des erreurs de page)';
        }
        if ($s['slowdowns'] > 0 || $s['pauses'] > 0) {
            $parts[] = sprintf('%d ralentissement(s), %d pause(s), %d s de pause', $s['slowdowns'], $s['pauses'], $s['paused_seconds']);
        }
        if (!empty($parts)) {
            echo "\n \033[36mℹ Profondeur " . $this->depth . " : " . implode(' ; ', $parts) . "\033[0m";
        }
        echo "\n";
        flush();
        if (ob_get_level() > 0) {
            ob_flush();
        }
    }
}

/**
 * Classe simulant un objet Request de RollingCurl pour le mode JS
 */
class JsRequest
{
    private string $url;
    private string $responseText;
    private float $responseTime;
    private int $httpCode;
    private string $error;
    private string $redirectUrl;
    
    public function __construct(string $url, string $responseText, float $responseTime, int $httpCode = 200, string $error = '', string $redirectUrl = '')
    {
        $this->url = $url;
        $this->responseText = $responseText;
        $this->responseTime = $responseTime;
        $this->httpCode = $httpCode;
        $this->error = $error;
        $this->redirectUrl = $redirectUrl;
    }
    
    public function getUrl(): string
    {
        return $this->url;
    }
    
    public function getResponseText(): string
    {
        return $this->responseText;
    }
    
    public function getResponseInfo(): array
    {
        return [
            'http_code' => $this->httpCode,
            'total_time' => $this->responseTime,
            'content_type' => 'text/html',
            'redirect_url' => $this->redirectUrl,
            'url' => $this->url,
            'error' => $this->error,
            'size_download' => strlen($this->responseText)
        ];
    }
}

/**
 * Classe simulant un objet Request pour les retries cURL
 */
class RetryRequest
{
    private string $url;
    private string $responseText;
    private array $info;
    private int $errno;
    private string $error;

    public function __construct(string $url, string $responseText, array $info, int $errno = 0, string $error = '')
    {
        $this->url = $url;
        $this->responseText = $responseText;
        $this->info = $info;
        $this->errno = $errno;
        $this->error = $error;
    }

    public function getUrl(): string { return $this->url; }
    public function getResponseText(): string { return $this->responseText; }
    public function getResponseInfo(): array
    {
        return [
            'http_code' => $this->info['http_code'] ?? 0,
            'total_time' => $this->info['total_time'] ?? 0,
            'content_type' => $this->info['content_type'] ?? '',
            'redirect_url' => $this->info['redirect_url'] ?? '',
            'url' => $this->url,
            'error' => $this->error,
            'size_download' => $this->info['size_download'] ?? strlen($this->responseText),
            'starttransfer_time' => $this->info['starttransfer_time'] ?? 0,
        ];
    }
    public function getResponseErrno(): int { return $this->errno; }
    public function getResponseError(): string { return $this->error; }
}
