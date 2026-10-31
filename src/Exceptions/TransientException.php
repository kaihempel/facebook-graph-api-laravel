<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Exceptions;

/**
 * Temporäre Fehler (Codes 1, 2, Verbindungsfehler, HTTP 5xx). Werden automatisch mit Backoff wiederholt.
 */
class TransientException extends GraphException
{
    #[\Override]
    public function isTransient(): bool
    {
        return true;
    }
}
