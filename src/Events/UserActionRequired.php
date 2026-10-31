<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Events;

use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Exceptions\UserActionRequiredException;

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
