<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Contracts\MediaSource;
use Kuweh\MetaGraph\GraphManager;
use Kuweh\MetaGraph\Jobs\Concerns\ResolvesClient;
use Kuweh\MetaGraph\Jobs\Middleware\RespectMetaRateLimits;
use Kuweh\MetaGraph\Resources\Videos\VideoResource;

/**
 * Lädt ein Video per Resumable Upload hoch (fortsetzbar über die persistierte Session)
 * und veröffentlicht es. Der Publish-Schritt wird nie doppelt ausgeführt.
 */
final class UploadVideo implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesClient;

    public int $tries = 5;

    public int $timeout = 3600;

    public function __construct(
        public readonly string $pageId,
        public readonly MediaSource $source,
        public readonly AccessToken $userToken,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?DateTimeInterface $scheduledAt = null,
        public readonly ?AccessToken $pageToken = null,
        public readonly ?string $resumeKey = null,
        public readonly ?string $app = null,
    ) {}

    public function handle(GraphManager $manager, CacheFactory $cache): void
    {
        $key = $this->uniqueId();
        $publishedKey = 'meta-graph:video-published:'.$key;

        if ($cache->store()->has($publishedKey)) {
            return;
        }

        $videos = $this->pageClient($manager)->videos();
        $handle = $videos->upload($this->source, $this->userToken, $key);
        $video = $videos->publish($handle, $this->title, $this->description, $this->scheduledAt);

        $cache->store()->put($publishedKey, $video->id, 86400 * 7);
        $videos->forgetSession($key);
    }

    public function uniqueId(): string
    {
        return $this->resumeKey ?? $this->pageId.':'.VideoResource::defaultKey($this->source);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectMetaRateLimits($this->app)];
    }
}
