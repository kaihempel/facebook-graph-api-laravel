<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Uploads;

use Kuweh\MetaGraph\Contracts\UploadSessionStore;
use Kuweh\MetaGraph\Data\UploadSession;
use Kuweh\MetaGraph\Models\MetaGraphUpload;

/**
 * Persistiert Resumable-Upload-Sessions in der Tabelle meta_graph_uploads.
 */
final class EloquentUploadSessionStore implements UploadSessionStore
{
    public function find(string $key): ?UploadSession
    {
        $model = MetaGraphUpload::query()->where('key', $key)->first();

        return $model === null ? null : new UploadSession(
            id: $model->session_id,
            fileName: $model->file_name,
            fileLength: $model->file_length,
            fileType: $model->file_type,
            handle: $model->handle,
        );
    }

    public function store(string $key, UploadSession $session): void
    {
        MetaGraphUpload::query()->updateOrCreate(['key' => $key], [
            'session_id' => $session->id,
            'file_name' => $session->fileName,
            'file_length' => $session->fileLength,
            'file_type' => $session->fileType,
            'handle' => $session->handle,
        ]);
    }

    public function forget(string $key): void
    {
        MetaGraphUpload::query()->where('key', $key)->delete();
    }
}
