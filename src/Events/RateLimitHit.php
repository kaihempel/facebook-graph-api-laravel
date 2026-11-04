<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use KaiHempel\MetaGraph\Exceptions\RateLimitException;

/**
 * Meta hat einen Rate-Limit-Fehler geliefert.
 */
final readonly class RateLimitHit
{
    public function __construct(
        public string $app,
        public RateLimitException $exception,
    ) {}
}
