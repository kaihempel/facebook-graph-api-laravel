<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Exceptions\RateLimitException;

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
