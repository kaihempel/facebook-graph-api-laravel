<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Data\WebhookChange;

/**
 * Die Seite wurde erwähnt (Webhook-Feld "mention").
 */
final readonly class PageMentionReceived
{
    public function __construct(
        public WebhookChange $change,
    ) {}
}
