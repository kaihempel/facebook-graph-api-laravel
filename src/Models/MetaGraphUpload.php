<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $session_id
 * @property string $file_name
 * @property int $file_length
 * @property string $file_type
 * @property string|null $handle
 */
class MetaGraphUpload extends Model
{
    protected $table = 'meta_graph_uploads';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_length' => 'integer',
        ];
    }
}
