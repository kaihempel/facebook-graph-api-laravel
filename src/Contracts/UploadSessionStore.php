<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Contracts;

use Kuweh\MetaGraph\Data\UploadSession;

/**
 * Persistiert Upload-Sessions, damit ein Queue-Job nach einem Neustart fortsetzen kann.
 */
interface UploadSessionStore
{
    public function find(string $key): ?UploadSession;

    public function store(string $key, UploadSession $session): void;

    public function forget(string $key): void;
}
