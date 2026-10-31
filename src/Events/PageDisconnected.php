<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

/**
 * Der Token einer Seite wurde entfernt.
 */
final readonly class PageDisconnected
{
    public function __construct(
        public string $pageId,
        public string $app,
    ) {}
}
