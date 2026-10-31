<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Exceptions;

use RuntimeException;

/**
 * Der Facebook-Login wurde abgebrochen oder lieferte keinen Code.
 */
final class LoginFailedException extends RuntimeException implements MetaGraphException
{
    public function __construct(
        string $message,
        public readonly ?string $error = null,
        public readonly ?string $errorReason = null,
    ) {
        parent::__construct($message);
    }
}
