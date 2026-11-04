<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\GraphManager;
use KaiHempel\MetaGraph\Jobs\Concerns\ResolvesClient;
use KaiHempel\MetaGraph\Jobs\Middleware\RespectMetaRateLimits;
use KaiHempel\MetaGraph\Resources\Posts\PostDraft;

/**
 * Veröffentlicht oder plant einen Post. Ohne Page Token wird der gespeicherte Token der Seite verwendet.
 */
final class PublishPost implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesClient;

    public int $tries = 5;

    public function __construct(
        public readonly string $pageId,
        public readonly PostDraft $draft,
        public readonly ?DateTimeInterface $publishAt = null,
        public readonly ?AccessToken $pageToken = null,
        public readonly ?string $app = null,
    ) {}

    public function handle(GraphManager $manager): void
    {
        $posts = $this->pageClient($manager)->posts();

        $this->publishAt === null
            ? $posts->create($this->draft)
            : $posts->schedule($this->draft, $this->publishAt);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectMetaRateLimits($this->app)];
    }
}
