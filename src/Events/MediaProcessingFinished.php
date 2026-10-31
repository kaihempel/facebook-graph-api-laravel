<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Data\MediaStatus;

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
