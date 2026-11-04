<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Auth;

use Carbon\CarbonImmutable;
use KaiHempel\MetaGraph\Contracts\ListsTokens;
use KaiHempel\MetaGraph\Contracts\TokenRepository;
use KaiHempel\MetaGraph\Data\StoredToken;

/**
 * Flüchtige Implementierung, z. B. für Tests oder Setups ohne Datenbank.
 */
final class ArrayTokenRepository implements ListsTokens, TokenRepository
{
    /** @var array<string, StoredToken> */
    private array $tokens = [];

    public function find(string $ownerType, string $ownerId): ?AccessToken
    {
        return ($this->tokens[$this->key($ownerType, $ownerId)] ?? null)?->token;
    }

    public function store(string $ownerType, string $ownerId, AccessToken $token): void
    {
        $this->tokens[$this->key($ownerType, $ownerId)] = new StoredToken($ownerType, $ownerId, $token);
    }

    public function forget(string $ownerType, string $ownerId): void
    {
        unset($this->tokens[$this->key($ownerType, $ownerId)]);
    }

    /**
     * @return iterable<StoredToken>
     */
    public function all(): iterable
    {
        return array_values($this->tokens);
    }

    public function markValidated(string $ownerType, string $ownerId): void
    {
        $key = $this->key($ownerType, $ownerId);

        if (isset($this->tokens[$key])) {
            $this->tokens[$key] = new StoredToken($ownerType, $ownerId, $this->tokens[$key]->token, CarbonImmutable::now());
        }
    }

    private function key(string $ownerType, string $ownerId): string
    {
        return $ownerType.':'.$ownerId;
    }
}
