<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Resources\Videos;

use DateTimeInterface;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Contracts\MediaSource;
use Kuweh\MetaGraph\Contracts\UploadSessionStore;
use Kuweh\MetaGraph\Data\MediaStatus;
use Kuweh\MetaGraph\Data\UploadSession;
use Kuweh\MetaGraph\Data\Video;
use Kuweh\MetaGraph\Events\MediaUploadProgressed;
use Kuweh\MetaGraph\Exceptions\TransientException;
use Kuweh\MetaGraph\Graph\GraphClient;
use Kuweh\MetaGraph\Graph\GraphRequest;
use Kuweh\MetaGraph\Media\StringSource;
use Kuweh\MetaGraph\Resources\Resource;
use Kuweh\MetaGraph\Support\Dispatch;
use Kuweh\MetaGraph\Support\Values;

/**
 * Videos einer Seite.
 *
 * Standardweg ist die Resumable Upload API (Session mit User Token, Publish mit
 * Page Token einer Person mit CREATE_CONTENT-Task). Der ältere Chunk-Flow auf
 * graph-video.facebook.com ist als Fallback-Treiber verfügbar.
 */
final class VideoResource extends Resource
{
    public function __construct(
        GraphClient $client,
        private readonly UploadSessionStore $sessions,
    ) {
        parent::__construct($client);
    }

    /**
     * Lädt eine Datei über die Resumable Upload API hoch und liefert das Handle "h".
     * Mit $resumeKey wird die Session persistiert, sodass ein Neustart am serverseitigen
     * file_offset fortsetzt.
     */
    public function upload(MediaSource $source, ?AccessToken $userToken = null, ?string $resumeKey = null): string
    {
        if ($source->isRemote()) {
            throw new InvalidArgumentException('Remote videos are published via file_url and do not need an upload.');
        }

        $token = $userToken ?? $this->client->token();
        $resumeKey ??= self::defaultKey($source);
        $session = $this->sessions->find($resumeKey);

        if ($session?->handle !== null) {
            return $session->handle;
        }

        $offset = 0;

        if ($session === null) {
            $session = $this->startSession($source, $token);
            $this->sessions->store($resumeKey, $session);
        } else {
            $offset = $this->offset($session, $token);
        }

        $attempts = 0;

        while (true) {
            try {
                $handle = $this->transfer($session, $source, $token, $offset);
                $this->sessions->store($resumeKey, $session->withHandle($handle));
                Dispatch::event(new MediaUploadProgressed($session->id, $session->fileLength, $session->fileLength));

                return $handle;
            } catch (TransientException $e) {
                // Kein Neustart des Flows: nur den abgebrochenen Schritt am Server-Offset fortsetzen.
                if (++$attempts > $this->client->transport()->retryPolicy()->times) {
                    throw $e;
                }

                $this->client->transport()->retryPolicy()->sleep($attempts);
                $offset = $this->offset($session, $token);
                Dispatch::event(new MediaUploadProgressed($session->id, $offset, $session->fileLength));
            }
        }
    }

    /**
     * Veröffentlicht ein Video auf der Seite (/{page-id}/videos).
     *
     * @param  MediaSource|string  $source  Medienquelle oder Handle aus upload()
     * @param  array<string, mixed>  $params
     */
    public function publish(
        MediaSource|string $source,
        ?string $title = null,
        ?string $description = null,
        ?DateTimeInterface $scheduledAt = null,
        array $params = [],
        ?AccessToken $uploadToken = null,
        ?string $resumeKey = null,
    ): Video {
        $params = array_filter([
            'title' => $title,
            'description' => $description,
            ...$params,
        ], static fn (mixed $v): bool => $v !== null);

        if ($scheduledAt !== null) {
            $params['published'] = false;
            $params['scheduled_publish_time'] = $scheduledAt;
        }

        if ($source instanceof MediaSource && ! $source->isRemote() && $this->client->options()->videoDriver === 'chunked') {
            return $this->publishChunked($source, $params);
        }

        $params += match (true) {
            is_string($source) => ['fbuploader_video_file_chunk' => $source],
            $source->isRemote() => ['file_url' => $source->remoteUrl()],
            default => ['fbuploader_video_file_chunk' => $this->upload($source, $uploadToken, $resumeKey)],
        };

        // Publish-Schritte werden nie blind wiederholt.
        $request = $this->client->request('POST', $this->client->pageId().'/videos', $params)->retryable(false);
        $response = $this->client->send($request);

        return Video::fromArray($response->raw() + ['title' => $title, 'description' => $description]);
    }

    public function status(string $videoId): MediaStatus
    {
        return MediaStatus::fromArray($videoId, $this->client->node($videoId)->fields('status')->get()->raw());
    }

    /**
     * Fallback-Treiber: upload_phase=start|transfer|finish auf graph-video.facebook.com.
     *
     * @param  array<string, mixed>  $params
     */
    public function publishChunked(MediaSource $source, array $params = []): Video
    {
        $path = $this->client->pageId().'/videos';
        $size = $source->size();
        $timeout = $this->client->options()->uploadTimeout;

        $start = $this->client->send(
            $this->client->request('POST', $path, ['upload_phase' => 'start', 'file_size' => $size])
                ->host(GraphRequest::VIDEO_HOST)
                ->retryable(false),
        )->raw();

        $sessionId = Values::string($start, 'upload_session_id', '') ?? '';
        $videoId = Values::string($start, 'video_id', '') ?? '';
        $startOffset = Values::int($start, 'start_offset', 0) ?? 0;
        $endOffset = Values::int($start, 'end_offset', 0) ?? 0;

        while ($startOffset < $endOffset) {
            $chunk = $source->read($startOffset, $endOffset - $startOffset);

            $transfer = $this->client->send(
                $this->client->request('POST', $path, [
                    'upload_phase' => 'transfer',
                    'upload_session_id' => $sessionId,
                    'start_offset' => $startOffset,
                ])
                    ->host(GraphRequest::VIDEO_HOST)
                    ->attach('video_file_chunk', new StringSource($chunk, $source->fileName(), $source->mimeType()))
                    ->timeout($timeout),
            )->raw();

            $startOffset = Values::int($transfer, 'start_offset', $endOffset) ?? $endOffset;
            $endOffset = Values::int($transfer, 'end_offset', $endOffset) ?? $endOffset;
            Dispatch::event(new MediaUploadProgressed($sessionId, $startOffset, $size));
        }

        $this->client->send(
            $this->client->request('POST', $path, [
                'upload_phase' => 'finish',
                'upload_session_id' => $sessionId,
                ...$params,
            ])->host(GraphRequest::VIDEO_HOST)->retryable(false),
        );

        return Video::fromArray([
            'id' => $videoId,
            'title' => $params['title'] ?? null,
            'description' => $params['description'] ?? null,
        ]);
    }

    /**
     * Aktueller file_offset einer Resumable-Upload-Session (GET /upload:{session}).
     */
    public function offset(UploadSession $session, ?AccessToken $token = null): int
    {
        $response = $this->client->send(
            $this->client->request('GET', $session->id)
                ->withToken($token ?? $this->client->token())
                ->tokenInHeader(),
        );

        return Values::int($response->raw(), 'file_offset', 0) ?? 0;
    }

    public function forgetSession(string $resumeKey): void
    {
        $this->sessions->forget($resumeKey);
    }

    public static function defaultKey(MediaSource $source): string
    {
        return 'video:'.sha1($source->fileName().'|'.$source->size().'|'.$source->mimeType());
    }

    private function startSession(MediaSource $source, ?AccessToken $token): UploadSession
    {
        $request = $this->client->request('POST', $this->client->app()->appId.'/uploads', [
            'file_name' => $source->fileName(),
            'file_length' => $source->size(),
            'file_type' => $source->mimeType(),
        ])->withToken($token)->retryable(false);

        $id = Values::string($this->client->send($request)->raw(), 'id', '') ?? '';

        return new UploadSession($id, $source->fileName(), $source->size(), $source->mimeType());
    }

    private function transfer(UploadSession $session, MediaSource $source, ?AccessToken $token, int $offset): string
    {
        $stream = new LimitStream(Utils::streamFor($source->openStream()), -1, $offset);

        $request = $this->client->request('POST', $session->id)
            ->withToken($token)
            ->tokenInHeader()
            ->withHeaders(['file_offset' => (string) $offset, 'Content-Type' => 'application/octet-stream'])
            ->withBody($stream)
            ->timeout($this->client->options()->uploadTimeout)
            ->retryable(false);

        return Values::string($this->client->send($request)->raw(), 'h', '') ?? '';
    }
}
