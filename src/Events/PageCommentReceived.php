<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Data\WebhookChange;

/**
 * Neuer oder geänderter Kommentar auf einer Seite (feed mit item=comment).
 */
final readonly class PageCommentReceived
{
    public function __construct(
        public WebhookChange $change,
    ) {}
}
