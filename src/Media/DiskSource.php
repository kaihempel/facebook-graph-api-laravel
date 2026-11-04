<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DiskSource extends MediaSource
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
    ) {}

    public function fileName(): string
    {
        return basename($this->path);
    }

    public function mimeType(): string
    {
        $filesystem = $this->filesystem();
        $mime = method_exists($filesystem, 'mimeType') ? $filesystem->mimeType($this->path) : false;

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    public function size(): int
    {
        return $this->filesystem()->size($this->path);
    }

    public function openStream()
    {
        $stream = $this->filesystem()->readStream($this->path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to open [{$this->path}] on disk [{$this->disk}].");
        }

        return $stream;
    }

    private function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }
}
