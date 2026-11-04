<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use KaiHempel\MetaGraph\Data\WebhookChange;

/**
 * Generisches Event je Webhook-Änderung.
 */
final readonly class WebhookReceived
{
    public function __construct(
        public WebhookChange $change,
    ) {}
}
