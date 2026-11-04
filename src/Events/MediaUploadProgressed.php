<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

/**
 * Fortschritt eines Video- oder Reel-Uploads.
 */
final readonly class MediaUploadProgressed
{
    public function __construct(
        public string $uploadId,
        public int $bytesTransferred,
        public int $totalBytes,
    ) {}
}
