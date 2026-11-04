<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Typsichere Zugriffe auf rohe Graph-Antworten.
 */
final class Values
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key, ?string $default = null): ?string
    {
        $value = data_get($data, $key);

        return is_scalar($value) && ! is_bool($value) ? (string) $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $key, ?int $default = null): ?int
    {
        $value = data_get($data, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function bool(array $data, string $key, ?bool $default = null): ?bool
    {
        $value = data_get($data, $key);

        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'false' => false,
            default => $default,
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = data_get($data, $key);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        return array_values(array_map(
            strval(...),
            array_filter(self::array($data, $key), static fn (mixed $v): bool => is_scalar($v)),
        ));
    }

    /**
     * Akzeptiert ISO-8601-Strings (created_time) und Unix-Timestamps (expires_at).
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function date(array $data, string $key): ?CarbonImmutable
    {
        $value = data_get($data, $key);

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value > 0 ? CarbonImmutable::createFromTimestamp((int) $value) : null;
        }

        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
