<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Exceptions;

use InvalidArgumentException;

final class ConfigurationException extends InvalidArgumentException implements MetaGraphException {}
