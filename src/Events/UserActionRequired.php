<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Events;

use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Exceptions\UserActionRequiredException;

/**
 * Der Nutzer muss sich bei Facebook einloggen (Checkpoint, Passwortwechsel).
 */
final readonly class UserActionRequired
{
    public function __construct(
        public ?AccessToken $token,
        public string $app,
        public string $hint,
        public UserActionRequiredException $exception,
    ) {}
}
