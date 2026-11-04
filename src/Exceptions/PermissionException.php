<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Exceptions;

/**
 * Fehlende Berechtigung (Codes 3, 10, 200–299).
 */
class PermissionException extends GraphException
{
    /**
     * Fehlender Scope, sofern er sich aus der Meldung ermitteln lässt.
     */
    public function missingScope(): ?string
    {
        $message = $this->getMessage();

        if (preg_match('/\b([a-z]+(?:_[a-z]+)+)\s+permission/i', $message, $m) === 1) {
            return strtolower($m[1]);
        }

        if (preg_match('/permission\(s\)\s+([a-z]+(?:_[a-z]+)+)/i', $message, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }
}
