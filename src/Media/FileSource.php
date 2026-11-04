<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Media;

use InvalidArgumentException;
use RuntimeException;

final class FileSource extends MediaSource
{
    public function __construct(
        public readonly string $path,
        private readonly ?string $fileName = null,
        private readonly ?string $mimeType = null,
    ) {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Media file [{$path}] does not exist or is not readable.");
        }
    }

    public function fileName(): string
    {
        return $this->fileName ?? basename($this->path);
    }

    public function mimeType(): string
    {
        if ($this->mimeType !== null) {
            return $this->mimeType;
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($this->path) : false;

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    public function size(): int
    {
        return (int) filesize($this->path);
    }

    public function openStream()
    {
        $stream = fopen($this->path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open media file [{$this->path}].");
        }

        return $stream;
    }
}
