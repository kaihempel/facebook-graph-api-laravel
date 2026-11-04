<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use KaiHempel\MetaGraph\Data\WebhookChange;

/**
 * Die Seite wurde erwähnt (Webhook-Feld "mention").
 */
final readonly class PageMentionReceived
{
    public function __construct(
        public WebhookChange $change,
    ) {}
}
