<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Http;

use Illuminate\Support\Sleep;
use KaiHempel\MetaGraph\Exceptions\GraphException;

/**
 * Exponentieller Backoff mit Jitter (Standard: 1 s, 4 s, 16 s) für
 * TransientException, Verbindungsfehler und HTTP 5xx.
 * Rate-Limit-Fehler werden bewusst nie im selben Prozess wiederholt.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $times = 3,
        public int $baseMs = 1000,
        public int $multiplier = 4,
        public bool $jitter = true,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $times = $config['times'] ?? 3;
        $base = $config['base_ms'] ?? 1000;

        return new self(
            times: is_numeric($times) ? max(0, (int) $times) : 3,
            baseMs: is_numeric($base) ? max(0, (int) $base) : 1000,
        );
    }

    public static function none(): self
    {
        return new self(times: 0);
    }

    /**
     * Ob nach dem gescheiterten Versuch Nr. $attempt (1-basiert) erneut versucht wird.
     */
    public function shouldRetry(int $attempt, ?GraphException $exception = null): bool
    {
        if ($attempt > $this->times) {
            return false;
        }

        return $exception === null || $exception->isTransient();
    }

    /**
     * Wartezeit vor dem Wiederholungsversuch nach Versuch Nr. $attempt.
     */
    public function delayMs(int $attempt): int
    {
        $delay = $this->baseMs * ($this->multiplier ** max(0, $attempt - 1));

        if ($this->jitter && $delay > 0) {
            $delay += random_int(0, (int) ($delay * 0.1));
        }

        return (int) $delay;
    }

    public function sleep(int $attempt): void
    {
        $ms = $this->delayMs($attempt);

        if ($ms > 0) {
            Sleep::for($ms)->milliseconds();
        }
    }
}
