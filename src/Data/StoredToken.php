<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Data;

use Carbon\CarbonImmutable;
use KaiHempel\MetaGraph\Auth\AccessToken;

final readonly class StoredToken
{
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public AccessToken $token,
        public ?CarbonImmutable $lastValidatedAt = null,
    ) {}
}
