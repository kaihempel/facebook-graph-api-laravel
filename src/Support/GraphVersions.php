<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Support;

use Carbon\CarbonImmutable;

/**
 * Bekannte Ablaufdaten der Graph-API-Versionen (laut Changelog).
 * Weitere Daten können über config('meta-graph.version_expirations') ergänzt werden.
 *
 * @see https://developers.facebook.com/docs/graph-api/changelog
 */
final class GraphVersions
{
    public const string LATEST = 'v26.0';

    /**
     * Version => Ablaufdatum (null = noch kein Ablaufdatum veröffentlicht).
     *
     * @var array<string, string|null>
     */
    public const array EXPIRATIONS = [
        'v25.0' => '2028-07-29',
        'v26.0' => null,
    ];

    /**
     * @param  array<array-key, mixed>  $overrides
     */
    public static function expiresAt(string $version, array $overrides = []): ?CarbonImmutable
    {
        $date = $overrides[$version] ?? self::EXPIRATIONS[$version] ?? null;

        return is_string($date) ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    /**
     * @param  array<array-key, mixed>  $overrides
     */
    public static function isKnown(string $version, array $overrides = []): bool
    {
        return array_key_exists($version, self::EXPIRATIONS) || array_key_exists($version, $overrides);
    }
}
