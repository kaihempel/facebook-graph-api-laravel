<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Exceptions;

/**
 * Temporär gesperrt (Code 368). Nur später erneut versuchen, kein automatischer Retry.
 */
class PolicyBlockException extends GraphException {}
