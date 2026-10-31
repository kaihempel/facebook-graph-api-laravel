<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Exceptions;

/**
 * Der Nutzer muss sich bei Facebook einloggen (Subcodes 459, 464 Checkpoint/unbestätigt, 460 Passwort geändert).
 */
class UserActionRequiredException extends AuthenticationException
{
    public function hint(): string
    {
        return $this->userMessage ?? match ($this->subcode) {
            460 => 'Das Facebook-Passwort wurde geändert. Bitte erneut anmelden.',
            459 => 'Das Facebook-Konto ist gesperrt (Checkpoint). Bitte bei Facebook anmelden.',
            464 => 'Das Facebook-Konto ist nicht bestätigt. Bitte bei Facebook anmelden.',
            default => 'Bitte bei Facebook anmelden, um den Zugriff wiederherzustellen.',
        };
    }
}
