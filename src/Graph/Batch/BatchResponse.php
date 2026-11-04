<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Graph\Batch;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use KaiHempel\MetaGraph\Graph\GraphClient;
use Traversable;

/**
 * Ergebnisse eines Batch Requests, iterierbar nach Name (falls gesetzt) bzw. Index.
 *
 * @implements IteratorAggregate<int|string, BatchItemResult>
 */
final readonly class BatchResponse implements Countable, IteratorAggregate
{
    /**
     * @param  list<BatchItemResult>  $items
     */
    public function __construct(
        private array $items,
        private ?GraphClient $client = null,
    ) {}

    /**
     * @return list<BatchItemResult>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return array<int|string, BatchItemResult>
     */
    public function all(): array
    {
        $result = [];

        foreach ($this->items as $position => $item) {
            $result[$item->name() ?? $position] = $item;
        }

        return $result;
    }

    public function get(int|string $key): ?BatchItemResult
    {
        return $this->all()[$key] ?? null;
    }

    public function failed(): self
    {
        return new self(array_values(array_filter($this->items, static fn (BatchItemResult $i): bool => $i->failed())), $this->client);
    }

    public function successful(): self
    {
        return new self(array_values(array_filter($this->items, static fn (BatchItemResult $i): bool => $i->successful())), $this->client);
    }

    public function timedOut(): self
    {
        return new self(array_values(array_filter($this->items, static fn (BatchItemResult $i): bool => $i->isTimedOut())), $this->client);
    }

    public function allSuccessful(): bool
    {
        return $this->failed()->count() === 0;
    }

    /**
     * Sendet die enthaltenen Operationen erneut (typisch: $results->failed()->retry()).
     */
    public function retry(): self
    {
        if ($this->items === [] || $this->client === null) {
            return $this;
        }

        return $this->client->batch(new BatchRequest(
            array_map(static fn (BatchItemResult $i): BatchOperation => $i->operation, $this->items),
        ));
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int|string, BatchItemResult>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }
}
