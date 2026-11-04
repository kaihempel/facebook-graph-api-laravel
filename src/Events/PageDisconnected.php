<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

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
