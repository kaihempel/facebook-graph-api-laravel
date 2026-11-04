<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Data;

use Carbon\CarbonImmutable;
use KaiHempel\MetaGraph\Support\Values;

final readonly class InsightValue
{
    /**
     * @param  int|float|string|array<array-key, mixed>|null  $value
     */
    public function __construct(
        public int|float|string|array|null $value,
        public ?CarbonImmutable $endTime = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $value = $data['value'] ?? null;

        return new self(
            value: is_int($value) || is_float($value) || is_string($value) || is_array($value) ? $value : null,
            endTime: Values::date($data, 'end_time'),
        );
    }

    /**
     * Numerischer Wert; bei Breakdowns (Arrays) die Summe.
     */
    public function total(): int|float
    {
        if (is_array($this->value)) {
            return array_sum(array_filter($this->value, is_numeric(...)));
        }

        return is_numeric($this->value) ? $this->value + 0 : 0;
    }
}
