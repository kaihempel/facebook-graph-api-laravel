<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

use Kuweh\MetaGraph\Exceptions\MediaProcessingException;
use Kuweh\MetaGraph\Support\Values;

/**
 * Status eines Videos oder Reels (Feld "status" mit uploading_phase,
 * processing_phase und publishing_phase).
 */
final readonly class MediaStatus
{
    /**
     * @param  list<array{code: int|null, message: string}>  $errors
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $videoId,
        public MediaState $state,
        public ?string $uploadingPhase = null,
        public int $bytesTransferred = 0,
        public ?string $processingPhase = null,
        public ?string $publishingPhase = null,
        public ?string $publishStatus = null,
        public array $errors = [],
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data  Antwort von GET /{video-id}?fields=status
     */
    public static function fromArray(string $videoId, array $data): self
    {
        $status = Values::array($data, 'status');
        $processing = Values::string($status, 'processing_phase.status');
        $uploading = Values::string($status, 'uploading_phase.status');

        $errors = [];
        foreach (['uploading_phase', 'processing_phase', 'publishing_phase'] as $phase) {
            foreach (Values::array($status, $phase.'.errors') as $error) {
                if (is_array($error)) {
                    $errors[] = [
                        'code' => Values::int($error, 'code'),
                        'message' => Values::string($error, 'message', 'Unknown processing error.') ?? '',
                    ];
                }
            }
        }

        $state = MediaState::fromGraph(Values::string($status, 'video_status'));

        if ($processing === 'error' || ($errors !== [] && ! $state->isFailed())) {
            $state = $uploading === 'error' ? MediaState::UploadFailed : MediaState::Error;
        }

        return new self(
            videoId: Values::string($data, 'id', $videoId) ?? $videoId,
            state: $state,
            uploadingPhase: $uploading,
            bytesTransferred: Values::int($status, 'uploading_phase.bytes_transfered')
                ?? Values::int($status, 'uploading_phase.bytes_transferred', 0) ?? 0,
            processingPhase: $processing,
            publishingPhase: Values::string($status, 'publishing_phase.status'),
            publishStatus: Values::string($status, 'publishing_phase.publish_status'),
            errors: $errors,
            raw: $data,
        );
    }

    public function isReady(): bool
    {
        return $this->state === MediaState::Ready;
    }

    public function isUploadComplete(): bool
    {
        return $this->uploadingPhase === 'complete'
            || in_array($this->state, [MediaState::UploadComplete, MediaState::Processing, MediaState::Ready], true);
    }

    public function isFailed(): bool
    {
        return $this->state->isFailed();
    }

    public function errorMessage(): ?string
    {
        if ($this->errors === []) {
            return $this->isFailed() ? "Media processing failed ({$this->state->value})." : null;
        }

        return implode(' ', array_map(static fn (array $e): string => $e['message'], $this->errors));
    }

    /**
     * Wirft Metas Meldung (z. B. zu geringe Auflösung) als MediaProcessingException.
     */
    public function throwIfFailed(): self
    {
        if ($this->isFailed()) {
            throw new MediaProcessingException(
                message: $this->errorMessage() ?? 'Media processing failed.',
                type: 'MediaProcessingException',
                code: $this->errors[0]['code'] ?? 0,
                payload: $this->raw,
            );
        }

        return $this;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
