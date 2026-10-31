<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Data\PageConnection;

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
