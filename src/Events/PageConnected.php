<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use KaiHempel\MetaGraph\Data\PageConnection;

/**
 * Eine Seite wurde verbunden und ihr Page Token gespeichert.
 */
final readonly class PageConnected
{
    public function __construct(
        public PageConnection $page,
        public string $app,
    ) {}
}
