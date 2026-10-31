<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Data\WebhookChange;

/**
 * Generisches Event je Webhook-Änderung.
 */
final readonly class WebhookReceived
{
    public function __construct(
        public WebhookChange $change,
    ) {}
}
