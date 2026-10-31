<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

use Kuweh\MetaGraph\Support\Values;

final readonly class InsightMetric
{
    /**
     * @param  list<InsightValue>  $values
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $name,
        public ?string $period = null,
        public ?string $title = null,
        public ?string $description = null,
        public array $values = [],
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Values::string($data, 'name', '') ?? '',
            period: Values::string($data, 'period'),
            title: Values::string($data, 'title'),
            description: Values::string($data, 'description'),
            values: array_values(array_map(
                InsightValue::fromArray(...),
                array_filter(Values::array($data, 'values'), is_array(...)),
            )),
            raw: $data,
        );
    }

    public function total(): int|float
    {
        return array_sum(array_map(static fn (InsightValue $v): int|float => $v->total(), $this->values));
    }

    public function latest(): ?InsightValue
    {
        return $this->values === [] ? null : $this->values[array_key_last($this->values)];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
