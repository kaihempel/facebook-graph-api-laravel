<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Data\RateLimitUsage;

/**
 * Die Auslastung hat den konfigurierten Schwellwert erreicht.
 */
final readonly class RateLimitApproaching
{
    public function __construct(
        public string $app,
        public RateLimitUsage $usage,
        public int $threshold,
    ) {}
}
