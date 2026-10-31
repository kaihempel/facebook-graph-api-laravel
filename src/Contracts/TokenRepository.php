<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Contracts;

use Kuweh\MetaGraph\Auth\AccessToken;

interface TokenRepository
{
    public function find(string $ownerType, string $ownerId): ?AccessToken;

    public function store(string $ownerType, string $ownerId, AccessToken $token): void;

    public function forget(string $ownerType, string $ownerId): void;
}
