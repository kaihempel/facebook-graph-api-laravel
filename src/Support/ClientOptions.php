<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Support;

/**
 * Laufzeitoptionen für Client und Ressourcen (aus config/meta-graph.php).
 */
final readonly class ClientOptions
{
    public function __construct(
        public string $version = 'v26.0',
        public int $maxPages = 50,
        public int $chunkSize = 8 * 1024 * 1024,
        public string $videoDriver = 'resumable',
        public int $uploadTimeout = 600,
        public int $minScheduleLeadMinutes = 10,
        public int $maxScheduleLeadDays = 30,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config  Inhalt von config('meta-graph')
     */
    public static function fromConfig(array $config): self
    {
        $int = static function (mixed $value, int $default): int {
            return is_numeric($value) ? (int) $value : $default;
        };
        $get = static function (string $section, string $key) use ($config): mixed {
            $values = $config[$section] ?? [];

            return is_array($values) ? ($values[$key] ?? null) : null;
        };

        $version = $config['version'] ?? null;
        $driver = $get('uploads', 'video_driver');

        return new self(
            version: is_string($version) && $version !== '' ? $version : 'v26.0',
            maxPages: $int($get('pagination', 'max_pages'), 50),
            chunkSize: max(1, $int($get('uploads', 'chunk_size'), 8 * 1024 * 1024)),
            videoDriver: is_string($driver) ? $driver : 'resumable',
            uploadTimeout: $int($get('http', 'upload_timeout'), 600),
            minScheduleLeadMinutes: $int($get('scheduling', 'min_lead_minutes'), 10),
            maxScheduleLeadDays: $int($get('scheduling', 'max_lead_days'), 30),
        );
    }

    public function withVersion(string $version): self
    {
        return new self($version, $this->maxPages, $this->chunkSize, $this->videoDriver, $this->uploadTimeout, $this->minScheduleLeadMinutes, $this->maxScheduleLeadDays);
    }
}
