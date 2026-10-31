<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Contracts;

use Kuweh\MetaGraph\Data\StoredToken;

/**
 * Optionale Erweiterung eines TokenRepository für den Token-Check.
 */
interface ListsTokens
{
    /**
     * @return iterable<StoredToken>
     */
    public function all(): iterable;

    public function markValidated(string $ownerType, string $ownerId): void;
}
