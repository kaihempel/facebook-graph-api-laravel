<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use KaiHempel\MetaGraph\Resources\Posts\PostDraft;
use Throwable;

/**
 * Ein Post konnte nicht erstellt werden. Bereits hochgeladene Fotos werden gemeldet, aber nicht gelöscht (Meta verwirft sie nach ca. 24 h).
 */
final readonly class PostFailed
{
    public function __construct(
        public PostDraft $draft,
        public string $pageId,
        public Throwable $exception,
        /** @var list<string> */
        public array $uploadedPhotoIds = [],
    ) {}
}
