<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Exceptions\GraphException;

/**
 * Ein Token ist ungültig geworden (Token-Check oder Auth-Fehler).
 */
final readonly class TokenInvalidated
{
    public function __construct(
        public ?AccessToken $token,
        public string $app,
        public ?GraphException $exception = null,
        public ?string $ownerType = null,
        public ?string $ownerId = null,
    ) {}
}
