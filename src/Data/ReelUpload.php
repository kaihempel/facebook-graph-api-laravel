<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

/**
 * Ergebnis von upload_phase=start auf /{page-id}/video_reels.
 */
final readonly class ReelUpload
{
    public function __construct(
        public string $videoId,
        public string $uploadUrl,
    ) {}
}
