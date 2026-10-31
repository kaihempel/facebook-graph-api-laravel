<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Uploads;

use Kuweh\MetaGraph\Contracts\UploadSessionStore;
use Kuweh\MetaGraph\Data\UploadSession;

final class ArrayUploadSessionStore implements UploadSessionStore
{
    /** @var array<string, UploadSession> */
    private array $sessions = [];

    public function find(string $key): ?UploadSession
    {
        return $this->sessions[$key] ?? null;
    }

    public function store(string $key, UploadSession $session): void
    {
        $this->sessions[$key] = $session;
    }

    public function forget(string $key): void
    {
        unset($this->sessions[$key]);
    }
}
