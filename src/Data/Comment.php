<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Data;

use Carbon\CarbonImmutable;
use KaiHempel\MetaGraph\Support\Values;

final readonly class Comment
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $message = null,
        public ?string $fromId = null,
        public ?string $fromName = null,
        public ?CarbonImmutable $createdTime = null,
        public ?bool $isHidden = null,
        public ?int $likeCount = null,
        public ?int $commentCount = null,
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Values::string($data, 'id', '') ?? '',
            message: Values::string($data, 'message'),
            fromId: Values::string($data, 'from.id'),
            fromName: Values::string($data, 'from.name'),
            createdTime: Values::date($data, 'created_time'),
            isHidden: Values::bool($data, 'is_hidden'),
            likeCount: Values::int($data, 'like_count'),
            commentCount: Values::int($data, 'comment_count'),
            raw: $data,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
