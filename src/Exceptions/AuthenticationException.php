<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Exceptions;

/**
 * Ungültiger oder abgelaufener Token (Codes 102, 190, OAuthException ohne Subcode, Subcodes 458, 463, 467).
 */
class AuthenticationException extends GraphException
{
    #[\Override]
    public function shouldReauthenticate(): bool
    {
        return true;
    }
}
