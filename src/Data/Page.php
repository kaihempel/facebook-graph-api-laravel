<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

use Kuweh\MetaGraph\Support\Values;

final readonly class Page
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $category = null,
        public ?int $fanCount = null,
        public ?int $followersCount = null,
        public ?string $link = null,
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Values::string($data, 'id', '') ?? '',
            name: Values::string($data, 'name'),
            category: Values::string($data, 'category'),
            fanCount: Values::int($data, 'fan_count'),
            followersCount: Values::int($data, 'followers_count'),
            link: Values::string($data, 'link'),
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
