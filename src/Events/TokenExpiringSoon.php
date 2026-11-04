<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use Carbon\CarbonImmutable;
use KaiHempel\MetaGraph\Auth\AccessToken;

/**
 * Ein User Token läuft bald ab (Token-Check).
 */
final readonly class TokenExpiringSoon
{
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public AccessToken $token,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
