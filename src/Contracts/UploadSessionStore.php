<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Contracts;

use KaiHempel\MetaGraph\Data\UploadSession;

/**
 * Persistiert Upload-Sessions, damit ein Queue-Job nach einem Neustart fortsetzen kann.
 */
interface UploadSessionStore
{
    public function find(string $key): ?UploadSession;

    public function store(string $key, UploadSession $session): void;

    public function forget(string $key): void;
}
