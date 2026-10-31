<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Support;

/**
 * Maskiert sensible Werte (Tokens, appsecret_proof, Secrets) für Logs und Exceptions.
 */
final class Redactor
{
    /** @var list<string> */
    public const array SENSITIVE_KEYS = [
        'access_token',
        'appsecret_proof',
        'client_secret',
        'fb_exchange_token',
        'input_token',
        'code',
        'authorization',
    ];

    public static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 6).'…'.str_repeat('*', 4);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = is_scalar($value) ? self::mask((string) $value) : '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }

    /**
     * Entfernt Tokens aus Query-Strings einer URL.
     */
    public static function url(string $url): string
    {
        return (string) preg_replace_callback(
            '/([?&](?:'.implode('|', array_map('preg_quote', self::SENSITIVE_KEYS)).')=)([^&#]*)/i',
            static fn (array $m): string => $m[1].self::mask(urldecode($m[2])),
            $url,
        );
    }
}
