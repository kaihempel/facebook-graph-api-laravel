<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Support\Values;

/**
 * Eine Seite aus /{user-id}/accounts inklusive Page Token und Tasks.
 */
final readonly class PageConnection
{
    /**
     * @param  list<string>  $tasks
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $name,
        public AccessToken $accessToken,
        public ?string $category = null,
        public array $tasks = [],
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Values::string($data, 'id', '') ?? '',
            name: Values::string($data, 'name', '') ?? '',
            accessToken: AccessToken::page(Values::string($data, 'access_token', '') ?? ''),
            category: Values::string($data, 'category'),
            tasks: Values::strings($data, 'tasks'),
            raw: $data,
        );
    }

    public function can(string $task): bool
    {
        return in_array(strtoupper($task), $this->tasks, true);
    }

    /**
     * Für Video-Publishing verlangt Meta den Task CREATE_CONTENT.
     */
    public function canCreateContent(): bool
    {
        return $this->can('CREATE_CONTENT');
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
