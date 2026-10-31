<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Auth;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use JsonSerializable;
use Kuweh\MetaGraph\Support\Redactor;

final readonly class AccessToken implements JsonSerializable
{
    public ?CarbonImmutable $expiresAt;

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $value,
        public TokenType $type,
        ?DateTimeInterface $expiresAt = null,
        public array $scopes = [],
    ) {
        $this->expiresAt = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt);
    }

    /**
     * @param  list<string>  $scopes
     */
    public static function user(string $value, ?DateTimeInterface $expiresAt = null, array $scopes = []): self
    {
        return new self($value, TokenType::User, $expiresAt, $scopes);
    }

    /**
     * @param  list<string>  $scopes
     */
    public static function page(string $value, array $scopes = []): self
    {
        return new self($value, TokenType::Page, null, $scopes);
    }

    public static function systemUser(string $value): self
    {
        return new self($value, TokenType::SystemUser);
    }

    public static function app(string $appId, string $appSecret): self
    {
        return new self($appId.'|'.$appSecret, TokenType::App);
    }

    /**
     * Erzeugt einen Token aus einer OAuth-Antwort ({access_token, expires_in}).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromOAuthResponse(array $payload, TokenType $type = TokenType::User): self
    {
        $value = $payload['access_token'] ?? '';
        $expiresIn = $payload['expires_in'] ?? null;

        return new self(
            value: is_string($value) ? $value : '',
            type: $type,
            expiresAt: is_numeric($expiresIn) && (int) $expiresIn > 0
                ? CarbonImmutable::now()->addSeconds((int) $expiresIn)
                : null,
        );
    }

    public function isExpired(?DateTimeInterface $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    public function expiresWithin(int $seconds): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->lessThanOrEqualTo(CarbonImmutable::now()->addSeconds($seconds));
    }

    public function masked(): string
    {
        return Redactor::mask($this->value);
    }

    /**
     * @return array{value: string, type: string, expires_at: string|null, scopes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'type' => $this->type->value,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'scopes' => $this->scopes,
        ];
    }

    /**
     * Serialisiert den Token maskiert, damit er nicht versehentlich in Logs landet.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['value' => $this->masked()] + $this->toArray();
    }

    public function __toString(): string
    {
        return $this->masked();
    }
}
