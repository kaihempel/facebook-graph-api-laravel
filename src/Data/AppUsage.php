<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

/**
 * Inhalt des Headers X-App-Usage (Platform Limits, Prozentwerte).
 */
final readonly class AppUsage
{
    public function __construct(
        public int $callCount = 0,
        public int $totalCputime = 0,
        public int $totalTime = 0,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            callCount: self::int($data['call_count'] ?? 0),
            totalCputime: self::int($data['total_cputime'] ?? 0),
            totalTime: self::int($data['total_time'] ?? 0),
        );
    }

    public function max(): int
    {
        return max($this->callCount, $this->totalCputime, $this->totalTime);
    }

    /**
     * @return array{call_count: int, total_cputime: int, total_time: int}
     */
    public function toArray(): array
    {
        return [
            'call_count' => $this->callCount,
            'total_cputime' => $this->totalCputime,
            'total_time' => $this->totalTime,
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
