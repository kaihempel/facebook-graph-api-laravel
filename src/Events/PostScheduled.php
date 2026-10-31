<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Data\Post;

/**
 * Ein Post wurde geplant.
 */
final readonly class PostScheduled
{
    public function __construct(
        public Post $post,
        public string $pageId,
    ) {}
}
