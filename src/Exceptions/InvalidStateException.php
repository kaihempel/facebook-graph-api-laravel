<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Exceptions;

use RuntimeException;

/**
 * Der OAuth-State aus dem Callback passt nicht zur Session (CSRF-Schutz).
 */
final class InvalidStateException extends RuntimeException implements MetaGraphException {}
