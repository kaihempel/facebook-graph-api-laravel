<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

use Carbon\CarbonImmutable;
use Kuweh\MetaGraph\Auth\TokenType;
use Kuweh\MetaGraph\Support\Values;

/**
 * Ergebnis von GET /debug_token.
 */
final readonly class TokenInfo
{
    /**
     * @param  list<string>  $scopes
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public bool $isValid,
        public ?TokenType $type = null,
        public ?string $appId = null,
        public ?string $userId = null,
        public ?string $profileId = null,
        public array $scopes = [],
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $dataAccessExpiresAt = null,
        public ?CarbonImmutable $issuedAt = null,
        public ?string $error = null,
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data  Inhalt von "data"
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isValid: Values::bool($data, 'is_valid', false) ?? false,
            type: TokenType::fromGraph(Values::string($data, 'type')),
            appId: Values::string($data, 'app_id'),
            userId: Values::string($data, 'user_id'),
            profileId: Values::string($data, 'profile_id'),
            scopes: Values::strings($data, 'scopes'),
            // expires_at = 0 bedeutet "läuft nie ab"
            expiresAt: Values::date($data, 'expires_at'),
            dataAccessExpiresAt: Values::date($data, 'data_access_expires_at'),
            issuedAt: Values::date($data, 'issued_at'),
            error: Values::string($data, 'error.message'),
            raw: $data,
        );
    }

    public function neverExpires(): bool
    {
        return $this->expiresAt === null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function missingScopes(array $scopes): array
    {
        return array_values(array_diff($scopes, $this->scopes));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
