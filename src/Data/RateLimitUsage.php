<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Data;

/**
 * Ausgewertete Rate-Limit-Header einer Antwort.
 */
final readonly class RateLimitUsage
{
    /**
     * @param  list<BusinessUseCaseUsage>  $businessUseCases
     */
    public function __construct(
        public ?AppUsage $app = null,
        public array $businessUseCases = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $headers  Header-Name => string|list<string>
     */
    public static function fromHeaders(array $headers): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $value = is_array($value) ? reset($value) : $value;
            if (is_string($value)) {
                $normalized[strtolower((string) $name)] = $value;
            }
        }

        $app = null;
        if (isset($normalized['x-app-usage'])) {
            $decoded = json_decode($normalized['x-app-usage'], true);
            $app = is_array($decoded) ? AppUsage::fromArray($decoded) : null;
        }

        $business = [];
        if (isset($normalized['x-business-use-case-usage'])) {
            $decoded = json_decode($normalized['x-business-use-case-usage'], true);
            foreach (is_array($decoded) ? $decoded : [] as $businessId => $entries) {
                foreach (is_array($entries) ? $entries : [] as $entry) {
                    if (is_array($entry)) {
                        $business[] = BusinessUseCaseUsage::fromArray((string) $businessId, $entry);
                    }
                }
            }
        }

        return new self($app, $business);
    }

    public function isEmpty(): bool
    {
        return $this->app === null && $this->businessUseCases === [];
    }

    /**
     * Höchster Prozentwert über alle Header und Metriken.
     */
    public function max(): int
    {
        $values = array_map(static fn (BusinessUseCaseUsage $u): int => $u->max(), $this->businessUseCases);
        $values[] = $this->app?->max() ?? 0;

        return max($values);
    }

    public function isApproaching(int $threshold): bool
    {
        return ! $this->isEmpty() && $this->max() >= $threshold;
    }

    /**
     * Höchste von Meta geschätzte Wartezeit in Sekunden.
     */
    public function secondsToRegainAccess(): int
    {
        return array_reduce(
            $this->businessUseCases,
            static fn (int $carry, BusinessUseCaseUsage $u): int => max($carry, $u->secondsToRegainAccess()),
            0,
        );
    }

    /**
     * @return array{app: array<string, int>|null, business_use_cases: list<array<string, int|string>>}
     */
    public function toArray(): array
    {
        return [
            'app' => $this->app?->toArray(),
            'business_use_cases' => array_map(static fn (BusinessUseCaseUsage $u): array => $u->toArray(), $this->businessUseCases),
        ];
    }
}
