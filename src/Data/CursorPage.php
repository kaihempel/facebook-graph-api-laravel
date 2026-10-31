<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Eine Seite einer Edge-Antwort.
 *
 * @template TItem
 *
 * @implements IteratorAggregate<int, TItem>
 */
final readonly class CursorPage implements Countable, IteratorAggregate
{
    /**
     * @param  list<TItem>  $items
     * @param  array<array-key, mixed>  $paging
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
        public ?string $previousCursor = null,
        public bool $hasMore = false,
        public array $paging = [],
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $response
     * @return CursorPage<array<array-key, mixed>>
     */
    public static function fromResponse(array $response): self
    {
        $data = $response['data'] ?? [];
        $paging = is_array($response['paging'] ?? null) ? $response['paging'] : [];
        $cursors = is_array($paging['cursors'] ?? null) ? $paging['cursors'] : [];
        $after = $cursors['after'] ?? null;
        $before = $cursors['before'] ?? null;
        $hasMore = isset($paging['next']) && is_string($paging['next']) && $paging['next'] !== '';

        return new self(
            items: is_array($data) ? array_values(array_filter($data, is_array(...))) : [],
            nextCursor: $hasMore && is_string($after) ? $after : null,
            previousCursor: is_string($before) ? $before : null,
            hasMore: $hasMore,
            paging: $paging,
            raw: $response,
        );
    }

    /**
     * @template TMapped
     *
     * @param  callable(TItem): TMapped  $callback
     * @return CursorPage<TMapped>
     */
    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items), $this->nextCursor, $this->previousCursor, $this->hasMore, $this->paging, $this->raw);
    }

    public function nextUrl(): ?string
    {
        $next = $this->paging['next'] ?? null;

        return is_string($next) ? $next : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return Traversable<int, TItem>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
