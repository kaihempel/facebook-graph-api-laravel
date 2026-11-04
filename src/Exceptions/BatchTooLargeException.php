<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Exceptions;

use InvalidArgumentException;

final class BatchTooLargeException extends InvalidArgumentException implements MetaGraphException
{
    public static function make(int $count, int $limit): self
    {
        return new self("A Graph batch may contain at most {$limit} operations, {$count} given.");
    }
}
