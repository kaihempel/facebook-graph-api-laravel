<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Media;

use InvalidArgumentException;
use LogicException;

final class UrlSource extends MediaSource
{
    public function __construct(
        public readonly string $url,
        private readonly ?string $name = null,
        private readonly ?string $mime = null,
        private readonly ?int $bytes = null,
    ) {
        if (! str_starts_with(strtolower($url), 'https://') && ! str_starts_with(strtolower($url), 'http://')) {
            throw new InvalidArgumentException("Media URL must be an http(s) URL, [{$url}] given.");
        }
    }

    #[\Override]
    public function isRemote(): bool
    {
        return true;
    }

    #[\Override]
    public function remoteUrl(): string
    {
        return $this->url;
    }

    public function fileName(): string
    {
        return $this->name ?? (basename((string) parse_url($this->url, PHP_URL_PATH)) ?: 'media');
    }

    public function mimeType(): string
    {
        return $this->mime ?? 'application/octet-stream';
    }

    public function size(): int
    {
        return $this->bytes ?? 0;
    }

    public function openStream()
    {
        throw new LogicException('Remote media sources are passed to Meta by URL and cannot be streamed.');
    }

    #[\Override]
    public function read(int $offset, int $length): string
    {
        throw new LogicException('Remote media sources are passed to Meta by URL and cannot be read.');
    }
}
