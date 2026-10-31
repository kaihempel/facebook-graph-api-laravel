<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Exceptions;

use InvalidArgumentException;

final class ConfigurationException extends InvalidArgumentException implements MetaGraphException {}
