<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Data\WebhookChange;

/**
 * Änderung im Feed einer Seite (Webhook-Feld "feed").
 */
final readonly class PageFeedChanged
{
    public function __construct(
        public WebhookChange $change,
    ) {}
}
