<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

/**
 * Session der Resumable Upload API (POST /{app-id}/uploads).
 */
final readonly class UploadSession
{
    public function __construct(
        public string $id,
        public string $fileName,
        public int $fileLength,
        public string $fileType,
        public ?string $handle = null,
    ) {}

    public function withHandle(string $handle): self
    {
        return new self($this->id, $this->fileName, $this->fileLength, $this->fileType, $handle);
    }

    public function isComplete(): bool
    {
        return $this->handle !== null;
    }
}
