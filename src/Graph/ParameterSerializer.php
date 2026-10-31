<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Graph;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Serialisiert Parameter wie von der Graph API verlangt:
 * list/object als JSON-String, bool als "true"/"false",
 * DateTimeInterface als Unix-Timestamp.
 */
final class ParameterSerializer
{
    /**
     * @param  array<array-key, mixed>  $params
     * @return array<string, string>
     */
    public static function serialize(array $params): array
    {
        $result = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            $result[(string) $key] = self::value($value);
        }

        return $result;
    }

    public static function value(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof DateTimeInterface => (string) $value->getTimestamp(),
            $value instanceof FieldSet => $value->toString(),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Stringable => (string) $value,
            is_array($value), $value instanceof JsonSerializable => (string) json_encode(
                self::normalize($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            default => (string) json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * Normalisiert verschachtelte Strukturen vor dem JSON-Encoding
     * (z. B. DateTime in verschachtelten Objekten).
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return match (true) {
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            $value instanceof FieldSet => $value->toString(),
            $value instanceof BackedEnum => $value->value,
            default => $value,
        };
    }
}
