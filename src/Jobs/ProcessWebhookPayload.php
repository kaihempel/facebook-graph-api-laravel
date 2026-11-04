<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KaiHempel\MetaGraph\Data\WebhookChange;
use KaiHempel\MetaGraph\Events\PageCommentReceived;
use KaiHempel\MetaGraph\Events\PageFeedChanged;
use KaiHempel\MetaGraph\Events\PageMentionReceived;
use KaiHempel\MetaGraph\Events\WebhookReceived;
use KaiHempel\MetaGraph\Support\Dispatch;
use KaiHempel\MetaGraph\Webhooks\WebhookPayload;

/**
 * Zerlegt eine Webhook-Zustellung, dedupliziert (Replay-Schutz) und feuert Events.
 */
final class ProcessWebhookPayload implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly ?string $app = null,
    ) {}

    public function handle(CacheFactory $cache, Config $config): void
    {
        $ttl = $config->get('meta-graph.webhooks.dedupe_ttl', 86400);
        $store = $config->get('meta-graph.rate_limit.cache_store');
        $repository = $cache->store(is_string($store) ? $store : null);

        foreach (WebhookPayload::parse($this->payload) as $change) {
            // Die Signatur enthält keinen Zeitstempel: Deduplizierung über einen Hash im Cache.
            $isNew = $repository->add('meta-graph:webhook:'.$change->hash(), true, is_numeric($ttl) ? (int) $ttl : 86400);

            if (! $isNew) {
                continue;
            }

            $this->dispatchEvents($change);
        }
    }

    private function dispatchEvents(WebhookChange $change): void
    {
        Dispatch::event(new WebhookReceived($change));

        if ($change->objectType !== 'page') {
            return;
        }

        match ($change->field) {
            'feed' => $this->feed($change),
            'mention' => Dispatch::event(new PageMentionReceived($change)),
            default => null,
        };
    }

    private function feed(WebhookChange $change): void
    {
        Dispatch::event(new PageFeedChanged($change));

        if ($change->item() === 'comment') {
            Dispatch::event(new PageCommentReceived($change));
        }
    }
}
