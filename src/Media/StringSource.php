<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Media;

use RuntimeException;

/**
 * In-Memory-Quelle, z. B. für einzelne Upload-Chunks.
 */
final class StringSource extends MediaSource
{
    public function __construct(
        private readonly string $contents,
        private readonly string $fileName = 'chunk',
        private readonly string $mimeType = 'application/octet-stream',
    ) {}

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    public function openStream()
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to open temporary stream.');
        }

        fwrite($stream, $this->contents);
        rewind($stream);

        return $stream;
    }
}
