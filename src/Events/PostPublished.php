<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use KaiHempel\MetaGraph\Data\Post;

/**
 * Ein Post wurde veröffentlicht.
 */
final readonly class PostPublished
{
    public function __construct(
        public Post $post,
        public string $pageId,
    ) {}
}
