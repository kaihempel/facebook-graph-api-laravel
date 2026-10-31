<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Graph;

use Stringable;

/**
 * Bildet die Field-Expansion-Syntax ab: edge.limit(n).as(alias){sub_fields}.
 *
 * @see https://developers.facebook.com/docs/graph-api/guides/field-expansion
 */
final readonly class FieldSet implements Stringable
{
    /**
     * @param  list<string|array{0: string, 1: FieldSet}>  $fields
     * @param  array<string, string>  $modifiers
     */
    private function __construct(
        private array $fields = [],
        private array $modifiers = [],
    ) {}

    public static function make(string ...$fields): self
    {
        return new self(array_values(array_filter(array_map(trim(...), $fields), static fn (string $f): bool => $f !== '')));
    }

    /**
     * @param  FieldSet|list<string>|string|null  $fields
     */
    public static function from(self|array|string|null $fields): self
    {
        return match (true) {
            $fields instanceof self => $fields,
            $fields === null => self::make(),
            is_string($fields) => self::make(...explode(',', $fields)),
            default => self::make(...$fields),
        };
    }

    public function add(string ...$fields): self
    {
        return new self([...$this->fields, ...self::make(...$fields)->fields], $this->modifiers);
    }

    /**
     * Erweitert ein Feld bzw. eine Edge um Unterfelder und Modifikatoren.
     *
     * @param  FieldSet|list<string>|null  $fields
     */
    public function expand(string $field, self|array|null $fields = null): self
    {
        return new self([...$this->fields, [$field, self::from($fields)]], $this->modifiers);
    }

    public function limit(int $limit): self
    {
        return $this->modifier('limit', (string) $limit);
    }

    public function summary(bool $summary = true): self
    {
        return $this->modifier('summary', $summary ? 'true' : 'false');
    }

    public function as(string $alias): self
    {
        return $this->modifier('as', $alias);
    }

    public function modifier(string $name, string|int|bool $value): self
    {
        $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return new self($this->fields, [...$this->modifiers, $name => $value]);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (string|array $f): string => is_string($f) ? $f : $f[0], $this->fields);
    }

    public function toString(): string
    {
        $parts = [];

        foreach ($this->fields as $field) {
            if (is_string($field)) {
                $parts[] = $field;

                continue;
            }

            [$name, $set] = $field;
            $parts[] = $name.$set->modifierString().($set->isEmpty() ? '' : '{'.$set->toString().'}');
        }

        return implode(',', $parts);
    }

    private function modifierString(): string
    {
        $result = '';

        foreach ($this->modifiers as $name => $value) {
            $result .= ".{$name}({$value})";
        }

        return $result;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
