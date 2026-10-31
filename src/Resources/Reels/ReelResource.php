<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Resources\Reels;

use DateTimeInterface;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Kuweh\MetaGraph\Contracts\MediaSource;
use Kuweh\MetaGraph\Data\MediaStatus;
use Kuweh\MetaGraph\Data\ReelUpload;
use Kuweh\MetaGraph\Data\Video;
use Kuweh\MetaGraph\Events\MediaUploadProgressed;
use Kuweh\MetaGraph\Exceptions\TransientException;
use Kuweh\MetaGraph\Graph\GraphRequest;
use Kuweh\MetaGraph\Resources\Resource;
use Kuweh\MetaGraph\Support\Dispatch;
use Kuweh\MetaGraph\Support\Values;

/**
 * Reels einer Seite: start → Upload an rupload.facebook.com → finish.
 * Meta erlaubt max. 30 API-Reels pro Seite in 24 h.
 *
 * @see https://developers.facebook.com/docs/video-api/guides/reels-publishing
 */
final class ReelResource extends Resource
{
    /**
     * Komplett: start, upload, finish.
     *
     * @param  array<string, mixed>  $params
     */
    public function publish(
        MediaSource $source,
        ?string $description = null,
        ?string $title = null,
        array $params = [],
        ?DateTimeInterface $scheduledAt = null,
    ): Video {
        $upload = $this->start();
        $this->upload($upload, $source);
        $this->finish($upload->videoId, $description, $title, $params, $scheduledAt);

        return Video::fromArray(['id' => $upload->videoId, 'title' => $title, 'description' => $description]);
    }

    public function start(): ReelUpload
    {
        $response = $this->client->send(
            $this->client->request('POST', $this->client->pageId().'/video_reels', ['upload_phase' => 'start'])
                ->retryable(false),
        )->raw();

        $videoId = Values::string($response, 'video_id', '') ?? '';

        return new ReelUpload(
            videoId: $videoId,
            uploadUrl: Values::string($response, 'upload_url')
                ?? 'https://'.GraphRequest::RUPLOAD_HOST.'/video-upload/'.$this->client->version().'/'.$videoId,
        );
    }

    /**
     * Upload an rupload.facebook.com, entweder per file_url (gehostete Datei) oder binär
     * mit offset und file_size. Abgebrochene Uploads setzen bei bytes_transfered fort.
     */
    public function upload(ReelUpload $upload, MediaSource $source, int $offset = 0): void
    {
        $base = (new GraphRequest('POST'))
            ->withUrl($upload->uploadUrl)
            ->withToken($this->client->token())
            ->tokenInHeader()
            ->retryable(false)
            ->timeout($this->client->options()->uploadTimeout);

        if ($source->isRemote()) {
            $this->client->send($base->withHeaders(['file_url' => (string) $source->remoteUrl()]));

            return;
        }

        $size = $source->size();
        $attempts = 0;

        while (true) {
            try {
                $stream = new LimitStream(Utils::streamFor($source->openStream()), -1, $offset);
                $this->client->send($base->withHeaders([
                    'offset' => (string) $offset,
                    'file_size' => (string) $size,
                    'Content-Type' => 'application/octet-stream',
                ])->withBody($stream));

                Dispatch::event(new MediaUploadProgressed($upload->videoId, $size, $size));

                return;
            } catch (TransientException $e) {
                if (++$attempts > $this->client->transport()->retryPolicy()->times) {
                    throw $e;
                }

                $this->client->transport()->retryPolicy()->sleep($attempts);
                $offset = $this->status($upload->videoId)->bytesTransferred;
                Dispatch::event(new MediaUploadProgressed($upload->videoId, $offset, $size));
            }
        }
    }

    /**
     * Veröffentlicht das hochgeladene Reel (video_state=PUBLISHED).
     * Bei einem Verbindungsfehler wird nicht blind wiederholt, sondern der Status geprüft.
     *
     * @param  array<string, mixed>  $params
     */
    public function finish(
        string $videoId,
        ?string $description = null,
        ?string $title = null,
        array $params = [],
        ?DateTimeInterface $scheduledAt = null,
    ): bool {
        $params = array_filter([
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => $scheduledAt === null ? 'PUBLISHED' : 'SCHEDULED',
            'scheduled_publish_time' => $scheduledAt,
            'description' => $description,
            'title' => $title,
            ...$params,
        ], static fn (mixed $v): bool => $v !== null);

        try {
            return $this->succeeded($this->client->send(
                $this->client->request('POST', $this->client->pageId().'/video_reels', $params)->retryable(false),
            ));
        } catch (TransientException $e) {
            $status = $this->status($videoId);

            if (in_array($status->publishingPhase, ['complete', 'in_progress'], true)) {
                return true;
            }

            throw $e;
        }
    }

    /**
     * Pollt uploading_phase, processing_phase und publishing_phase.
     */
    public function status(string $videoId): MediaStatus
    {
        return MediaStatus::fromArray($videoId, $this->client->node($videoId)->fields('status')->get()->raw());
    }
}
