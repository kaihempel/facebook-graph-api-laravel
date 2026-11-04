<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use KaiHempel\MetaGraph\Contracts\ListsTokens;
use KaiHempel\MetaGraph\Contracts\TokenRepository;
use KaiHempel\MetaGraph\Data\StoredToken;
use KaiHempel\MetaGraph\Models\MetaGraphToken;

/**
 * Speichert Tokens verschlüsselt in der Tabelle meta_graph_tokens.
 */
final class EloquentTokenRepository implements ListsTokens, TokenRepository
{
    public function find(string $ownerType, string $ownerId): ?AccessToken
    {
        $model = $this->query($ownerType, $ownerId)->first();

        return $model === null ? null : self::toToken($model);
    }

    public function store(string $ownerType, string $ownerId, AccessToken $token): void
    {
        MetaGraphToken::query()->updateOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId],
            [
                'token_type' => $token->type->value,
                'token' => $token->value,
                'scopes' => $token->scopes,
                'expires_at' => $token->expiresAt,
            ],
        );
    }

    public function forget(string $ownerType, string $ownerId): void
    {
        $this->query($ownerType, $ownerId)->delete();
    }

    /**
     * @return iterable<StoredToken>
     */
    public function all(): iterable
    {
        foreach (MetaGraphToken::query()->orderBy('id')->lazy() as $model) {
            yield new StoredToken($model->owner_type, $model->owner_id, self::toToken($model), $model->last_validated_at);
        }
    }

    public function markValidated(string $ownerType, string $ownerId): void
    {
        $this->query($ownerType, $ownerId)->update(['last_validated_at' => CarbonImmutable::now()]);
    }

    /**
     * @return Builder<MetaGraphToken>
     */
    private function query(string $ownerType, string $ownerId): Builder
    {
        return MetaGraphToken::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId);
    }

    private static function toToken(MetaGraphToken $model): AccessToken
    {
        return new AccessToken(
            value: $model->token,
            type: TokenType::tryFrom($model->token_type) ?? TokenType::User,
            expiresAt: $model->expires_at,
            scopes: $model->scopes ?? [],
        );
    }
}
