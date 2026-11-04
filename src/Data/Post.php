<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Data;

use Carbon\CarbonImmutable;
use KaiHempel\MetaGraph\Support\Values;

final readonly class Post
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $message = null,
        public ?CarbonImmutable $createdTime = null,
        public ?bool $isPublished = null,
        public ?CarbonImmutable $scheduledFor = null,
        public ?string $permalinkUrl = null,
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, ?CarbonImmutable $scheduledFor = null): self
    {
        return new self(
            id: Values::string($data, 'id', '') ?? '',
            message: Values::string($data, 'message'),
            createdTime: Values::date($data, 'created_time'),
            isPublished: Values::bool($data, 'is_published'),
            scheduledFor: Values::date($data, 'scheduled_publish_time') ?? $scheduledFor,
            permalinkUrl: Values::string($data, 'permalink_url'),
            raw: $data,
        );
    }

    public function isScheduled(): bool
    {
        return $this->scheduledFor !== null && $this->isPublished !== true;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
