<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use KaiHempel\MetaGraph\Data\MediaStatus;

/**
 * Ein Video oder Reel ist fertig verarbeitet (oder fehlgeschlagen).
 */
final readonly class MediaProcessingFinished
{
    public function __construct(
        public string $videoId,
        public MediaStatus $status,
    ) {}
}
