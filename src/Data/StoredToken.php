<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

use Carbon\CarbonImmutable;
use Kuweh\MetaGraph\Auth\AccessToken;

final readonly class StoredToken
{
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public AccessToken $token,
        public ?CarbonImmutable $lastValidatedAt = null,
    ) {}
}
