<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $token_type
 * @property string $token
 * @property list<string>|null $scopes
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_validated_at
 */
class MetaGraphToken extends Model
{
    protected $table = 'meta_graph_tokens';

    protected $guarded = [];

    protected $hidden = ['token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'immutable_datetime',
            'last_validated_at' => 'immutable_datetime',
        ];
    }
}
