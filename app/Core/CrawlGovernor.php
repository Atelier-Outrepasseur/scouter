<?php

namespace App\Core;

/**
 * Régulateur de débit d'un crawl : ralentit, met en pause puis reprend quand le
 * site commence à bloquer, au lieu d'enchaîner les échecs.
 *
 * - Débit et parallélisme de départ = ceux de la vitesse choisie. La variable
 *   MAX_CONCURRENT_CURL n'est plus qu'un PLAFOND : elle ne peut plus augmenter
 *   le parallélisme d'une vitesse limitée (very_slow, slow, fast).
 * - Ralentissement : dès 3 signaux de blocage consécutifs, ou 30 % de signaux
 *   sur les 20 dernières requêtes (minimum 6), le débit et le parallélisme sont
 *   divisés par deux (plancher : 1 requête/s, 1 connexion).
 * - Pause : si le site bloque encore au plancher, pause de 60 s, puis 120, 240,
 *   480, 600 s maximum. Après la pause, reprise au plancher.
 * - Réaccélération : après 20 réponses de suite sans signal, +1 requête/s et
 *   +1 connexion, sans dépasser la vitesse choisie NI le plafond appris : quand
 *   un épisode de blocage commence à un débit donné, le régulateur ne remonte
 *   plus au-delà de ce débit moins 1 requête/s (il ne retourne pas se faire bannir).
 * - Abandon : au-delà de 30 minutes de pause (réglable) SANS que le site ait
 *   répondu de nouveau entre-temps, shouldGiveUp() devient vrai ; le crawl
 *   s'arrête alors proprement (reprise possible). Le compteur repart de zéro dès
 *   que le site répond normalement : un blocage passager, même long, plus tôt
 *   dans le crawl ne provoque plus l'abandon au blocage suivant.
 *
 * Une instance par crawl (registre statique) : l'état survit aux DepthCrawler
 * successifs d'un même crawl.
 */
class CrawlGovernor
{
    public const SPEEDS = [
        // vitesse => [requêtes par seconde (0 = illimité), connexions simultanées]
        'very_slow' => [1, 1],
        'slow'      => [5, 3],
        'fast'      => [15, 8],
        'unlimited' => [0, 10],
    ];

    private const WINDOW = 20;
    private const MIN_SAMPLES = 6;
    private const RATIO_THRESHOLD = 0.3;
    private const CONSECUTIVE_THRESHOLD = 3;
    private const RECOVERY_STREAK = 20;
    private const FIRST_PAUSE = 60;
    private const MAX_PAUSE = 600;

    /** @var array<int, self> */
    private static array $registry = [];

    private int $maxRate;
    private int $maxConcurrency;
    private float $rate;
    private int $concurrency;
    private array $window = [];
    private int $consecutiveBlocks = 0;
    private int $okStreak = 0;
    private int $nextPause = self::FIRST_PAUSE;
    private float $pausedTotal = 0.0;
    /** Pause cumulée depuis la dernière fois où le site a de nouveau répondu */
    private float $pausedSinceRecovery = 0.0;
    private int $pauseCount = 0;
    private int $slowdowns = 0;
    private array $signalsByKind = [];
    private bool $pauseRequested = false;
    /** Débit maximal appris (requêtes/s) ; null = pas encore de blocage */
    private ?float $ceiling = null;
    private bool $episodeOpen = false;
    private int $maxPauseTotal;

    /** @var callable(): float */
    private $clock;
    /** @var callable(float): void */
    private $sleeper;
    /** @var callable(string): void */
    private $logger;

    public function __construct(
        string $speed,
        ?int $concurrencyCap = null,
        ?int $maxPauseTotal = null,
        ?callable $clock = null,
        ?callable $sleeper = null,
        ?callable $logger = null
    ) {
        [$rate, $conc] = self::SPEEDS[$speed] ?? self::SPEEDS['fast'];
        if ($speed === 'unlimited') {
            // Seul mode où la variable d'environnement fixe le parallélisme.
            $conc = $concurrencyCap && $concurrencyCap > 0 ? $concurrencyCap : $conc;
        } elseif ($concurrencyCap && $concurrencyCap > 0) {
            $conc = min($conc, $concurrencyCap);
        }
        $this->maxRate = $rate;
        $this->maxConcurrency = max(1, $conc);
        $this->rate = (float)$rate;
        $this->concurrency = $this->maxConcurrency;
        $this->maxPauseTotal = $maxPauseTotal ?? (int)(getenv('CRAWL_MAX_PAUSE_SECONDS') ?: 1800);
        $this->clock = $clock ?? fn() => microtime(true);
        $this->sleeper = $sleeper ?? function (float $s) { usleep((int)($s * 1e6)); };
        $this->logger = $logger ?? function (string $m) { echo "\n \033[33m⏸ $m\033[0m"; flush(); };
    }

    public static function forCrawl(int $crawlId, array $config): self
    {
        if (!isset(self::$registry[$crawlId])) {
            $cap = (int)($config['max_concurrent_curl'] ?? 0) ?: ((int)getenv('MAX_CONCURRENT_CURL') ?: null);
            self::$registry[$crawlId] = new self($config['crawl_speed'] ?? 'fast', $cap);
        }
        return self::$registry[$crawlId];
    }

    public static function reset(?int $crawlId = null): void
    {
        if ($crawlId === null) {
            self::$registry = [];
        } else {
            unset(self::$registry[$crawlId]);
        }
    }

    /** Requêtes par seconde actuelles (0 = pas de limite). */
    public function rate(): float
    {
        return $this->rate;
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    /** Taille du prochain lot : 1 seconde de travail au débit actuel. */
    public function batchSize(): int
    {
        if ($this->maxRate === 0 && $this->rate == 0.0) {
            return $this->concurrency * 2;
        }
        return max(1, (int)floor($this->rate));
    }

    /** Durée minimale d'un lot de $n requêtes pour respecter le débit. */
    public function minBatchDuration(int $n): float
    {
        return $this->rate > 0 ? $n / $this->rate : 0.0;
    }

    public function record(FetchOutcome $outcome): void
    {
        $signal = $outcome->isBlockingSignal();
        $this->window[] = $signal;
        if (count($this->window) > self::WINDOW) {
            array_shift($this->window);
        }

        if ($signal) {
            $this->signalsByKind[$outcome->kind] = ($this->signalsByKind[$outcome->kind] ?? 0) + 1;
            $this->consecutiveBlocks++;
            $this->okStreak = 0;
            if ($this->isBlocking()) {
                $this->onBlocking($outcome);
            }
            return;
        }

        $this->consecutiveBlocks = 0;
        $this->okStreak++;
        if ($this->pauseCount > 0 && $this->okStreak === 5) {
            // Le site répond de nouveau normalement : la prochaine pause repart à 60 s
            // et le compteur d'abandon repart de zéro.
            $this->nextPause = self::FIRST_PAUSE;
            $this->pausedSinceRecovery = 0.0;
        }
        if ($this->okStreak >= self::RECOVERY_STREAK) {
            $this->okStreak = 0;
            $this->episodeOpen = false;
            $this->speedUp();
        }
    }

    private function isBlocking(): bool
    {
        if ($this->consecutiveBlocks >= self::CONSECUTIVE_THRESHOLD) {
            return true;
        }
        $n = count($this->window);
        return $n >= self::MIN_SAMPLES && array_sum($this->window) / $n >= self::RATIO_THRESHOLD;
    }

    private function atFloor(): bool
    {
        return $this->rate > 0 && $this->rate <= 1.0 && $this->concurrency <= 1;
    }

    private function onBlocking(FetchOutcome $outcome): void
    {
        if (!$this->episodeOpen) {
            // Début d'un épisode de blocage : le débit actuel est trop élevé pour ce site.
            $this->episodeOpen = true;
            $current = $this->rate > 0 ? $this->rate : (float)($this->concurrency * 2);
            $this->ceiling = max(1.0, min($this->ceiling ?? INF, $current - 1));
        }
        if ($this->atFloor()) {
            $this->pauseRequested = true;
            return;
        }
        $this->slowdowns++;
        $base = $this->rate > 0 ? $this->rate : max(2, $this->concurrency * 2);
        $this->rate = max(1.0, floor($base / 2));
        $this->concurrency = max(1, intdiv($this->concurrency, 2));
        $this->window = [];
        $this->consecutiveBlocks = 0;
        ($this->logger)(sprintf(
            'Le site ralentit ou bloque (%s) : passage à %d requête(s)/s, %d connexion(s)',
            $outcome->label(), (int)$this->rate, $this->concurrency
        ));
    }

    private function speedUp(): void
    {
        $target = $this->maxRate > 0 ? $this->maxRate : 0;
        if ($this->ceiling !== null) {
            $target = $target > 0 ? min($target, $this->ceiling) : $this->ceiling;
        }
        if ($target > 0 && $this->rate < $target) {
            $this->rate = min($target, $this->rate + 1);
        } elseif ($target === 0 && $this->rate > 0) {
            // Retour progressif vers « illimité »
            $this->rate = $this->rate + 1 >= $this->maxConcurrency * 2 ? 0.0 : $this->rate + 1;
        }
        if ($this->concurrency < $this->maxConcurrency) {
            $this->concurrency++;
        }
    }

    public function shouldPause(): bool
    {
        return $this->pauseRequested;
    }

    /**
     * Effectue la pause demandée. $checkStop est appelé toutes les 5 s au plus
     * (il peut lever l'exception d'arrêt du crawl).
     * Retourne la durée de pause effectuée.
     */
    public function pause(?callable $checkStop = null): float
    {
        $duration = $this->nextPause;
        $this->pauseCount++;
        ($this->logger)(sprintf(
            'Le site ne répond plus au rythme minimal : pause de %d s (pause n°%d, %d s cumulées)',
            $duration, $this->pauseCount, (int)$this->pausedTotal
        ));
        $start = ($this->clock)();
        $remaining = (float)$duration;
        while ($remaining > 0) {
            $step = min(5.0, $remaining);
            ($this->sleeper)($step);
            $remaining -= $step;
            if ($checkStop) {
                $checkStop();
            }
        }
        $slept = max((float)$duration, ($this->clock)() - $start);
        $this->pausedTotal += $slept;
        $this->pausedSinceRecovery += $slept;
        $this->nextPause = min(self::MAX_PAUSE, $this->nextPause * 2);
        $this->pauseRequested = false;
        $this->window = [];
        $this->consecutiveBlocks = 0;
        $this->rate = 1.0;
        $this->concurrency = 1;
        ($this->logger)('Reprise au rythme minimal (1 requête/s)');
        return $slept;
    }

    public function shouldGiveUp(): bool
    {
        return $this->pausedSinceRecovery >= $this->maxPauseTotal;
    }

    public function stats(): array
    {
        return [
            'rate' => $this->rate,
            'concurrency' => $this->concurrency,
            'slowdowns' => $this->slowdowns,
            'pauses' => $this->pauseCount,
            'paused_seconds' => (int)$this->pausedTotal,
            'paused_since_recovery' => (int)$this->pausedSinceRecovery,
            'ceiling' => $this->ceiling,
            'signals' => $this->signalsByKind,
        ];
    }
}
