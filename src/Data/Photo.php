<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Data;

use KaiHempel\MetaGraph\Support\Values;

final readonly class Photo
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $postId = null,
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Values::string($data, 'id', '') ?? '',
            postId: Values::string($data, 'post_id'),
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
