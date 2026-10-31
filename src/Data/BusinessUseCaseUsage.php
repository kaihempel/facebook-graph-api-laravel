<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

/**
 * Ein Eintrag aus X-Business-Use-Case-Usage (pro Business-Objekt und Typ).
 */
final readonly class BusinessUseCaseUsage
{
    public function __construct(
        public string $businessId,
        public string $type,
        public int $callCount = 0,
        public int $totalCputime = 0,
        public int $totalTime = 0,
        public int $estimatedTimeToRegainAccess = 0,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(string $businessId, array $data): self
    {
        $int = static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;
        $type = $data['type'] ?? 'unknown';

        return new self(
            businessId: $businessId,
            type: is_string($type) ? $type : 'unknown',
            callCount: $int($data['call_count'] ?? 0),
            totalCputime: $int($data['total_cputime'] ?? 0),
            totalTime: $int($data['total_time'] ?? 0),
            estimatedTimeToRegainAccess: $int($data['estimated_time_to_regain_access'] ?? 0),
        );
    }

    public function max(): int
    {
        return max($this->callCount, $this->totalCputime, $this->totalTime);
    }

    /**
     * Wartezeit in Sekunden (Meta liefert Minuten).
     */
    public function secondsToRegainAccess(): int
    {
        return $this->estimatedTimeToRegainAccess * 60;
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'business_id' => $this->businessId,
            'type' => $this->type,
            'call_count' => $this->callCount,
            'total_cputime' => $this->totalCputime,
            'total_time' => $this->totalTime,
            'estimated_time_to_regain_access' => $this->estimatedTimeToRegainAccess,
        ];
    }
}
