<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Data;

use Carbon\CarbonImmutable;
use KaiHempel\MetaGraph\Support\Values;

/**
 * Ein einzelner changes[]-Eintrag einer Webhook-Zustellung.
 */
final readonly class WebhookChange
{
    /**
     * @param  array<array-key, mixed>  $value
     */
    public function __construct(
        public string $objectType,
        public string $objectId,
        public string $field,
        public array $value,
        public ?CarbonImmutable $time = null,
    ) {}

    /**
     * Wert eines Schlüssels aus "value" (z. B. item, verb, post_id).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->value, $key, $default);
    }

    public function item(): ?string
    {
        return Values::string($this->value, 'item');
    }

    public function verb(): ?string
    {
        return Values::string($this->value, 'verb');
    }

    /**
     * Hash für die Deduplizierung (Objekt-ID, Feld, Zeit und Wert).
     */
    public function hash(): string
    {
        return hash('sha256', implode('|', [
            $this->objectType,
            $this->objectId,
            $this->field,
            (string) $this->time?->getTimestamp(),
            (string) json_encode($this->value),
        ]));
    }

    /**
     * @return array{object: string, id: string, field: string, value: array<array-key, mixed>, time: int|null}
     */
    public function toArray(): array
    {
        return [
            'object' => $this->objectType,
            'id' => $this->objectId,
            'field' => $this->field,
            'value' => $this->value,
            'time' => $this->time?->getTimestamp(),
        ];
    }
}
