<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Contracts\MediaSource;
use KaiHempel\MetaGraph\Data\ReelUpload;
use KaiHempel\MetaGraph\Events\MediaProcessingFinished;
use KaiHempel\MetaGraph\GraphManager;
use KaiHempel\MetaGraph\Jobs\Concerns\ResolvesClient;
use KaiHempel\MetaGraph\Jobs\Middleware\RespectMetaRateLimits;
use KaiHempel\MetaGraph\Resources\Reels\ReelResource;
use KaiHempel\MetaGraph\Support\Dispatch;

/**
 * Reel-Flow als Job: Upload, Status-Polling mit release(), Finish, Verarbeitung abwarten.
 * Der Fortschritt wird im Cache gehalten, da release() den ursprünglichen Payload neu einreiht.
 */
final class PublishReel implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesClient;

    public int $tries = 60;

    public int $timeout = 3600;

    public function __construct(
        public readonly string $pageId,
        public readonly MediaSource $source,
        public readonly ?string $description = null,
        public readonly ?string $title = null,
        public readonly ?AccessToken $pageToken = null,
        public readonly int $pollSeconds = 30,
        public readonly ?string $key = null,
        public readonly ?string $app = null,
    ) {}

    public function handle(GraphManager $manager, CacheFactory $cache): void
    {
        $store = $cache->store();
        $reels = $this->pageClient($manager)->reels();
        $state = $this->state($store);

        if (! isset($state['video_id'])) {
            $upload = $reels->start();
            $state = ['video_id' => $upload->videoId, 'upload_url' => $upload->uploadUrl];
            $this->saveState($store, $state);
            $reels->upload($upload, $this->source);
        }

        $videoId = $state['video_id'];
        $status = $reels->status($videoId);

        if ($status->isFailed()) {
            $this->forgetState($store);
            Dispatch::event(new MediaProcessingFinished($videoId, $status));
            $status->throwIfFailed();
        }

        if (! isset($state['finished'])) {
            if (! $status->isUploadComplete()) {
                $this->resumeUpload($reels, $state, $status->bytesTransferred);
                $this->release($this->pollSeconds);

                return;
            }

            $reels->finish($videoId, $this->description, $this->title);
            $state['finished'] = '1';
            $this->saveState($store, $state);
            $status = $reels->status($videoId);
        }

        if ($status->isReady() || $status->publishingPhase === 'complete') {
            $this->forgetState($store);
            Dispatch::event(new MediaProcessingFinished($videoId, $status));

            return;
        }

        $status->throwIfFailed();
        $this->release($this->pollSeconds);
    }

    public function uniqueId(): string
    {
        return $this->key ?? $this->pageId.':reel:'.sha1($this->source->fileName().'|'.$this->source->size());
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectMetaRateLimits($this->app)];
    }

    /**
     * @param  array{video_id: string, upload_url?: string, finished?: string}  $state
     */
    private function resumeUpload(ReelResource $reels, array $state, int $offset): void
    {
        if ($this->source->isRemote() || $offset <= 0 || $offset >= $this->source->size()) {
            return;
        }

        $reels->upload(new ReelUpload($state['video_id'], $state['upload_url'] ?? ''), $this->source, $offset);
    }

    /**
     * @return array{video_id?: string, upload_url?: string, finished?: string}
     */
    private function state(Cache $store): array
    {
        $state = $store->get($this->stateKey());

        /** @var array{video_id?: string, upload_url?: string, finished?: string} */
        return is_array($state) ? $state : [];
    }

    /**
     * @param  array{video_id: string, upload_url?: string, finished?: string}  $state
     */
    private function saveState(Cache $store, array $state): void
    {
        $store->put($this->stateKey(), $state, 86400);
    }

    private function forgetState(Cache $store): void
    {
        $store->forget($this->stateKey());
    }

    private function stateKey(): string
    {
        return 'meta-graph:reel:'.$this->uniqueId();
    }
}
