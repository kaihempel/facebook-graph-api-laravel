<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Resources\Photos;

use Kuweh\MetaGraph\Contracts\MediaSource;
use Kuweh\MetaGraph\Data\Photo;
use Kuweh\MetaGraph\Resources\Resource;

/**
 * Fotos einer Seite (/{page-id}/photos), per URL oder Datei.
 */
final class PhotoResource extends Resource
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function publish(MediaSource $source, ?string $caption = null, array $params = []): Photo
    {
        return $this->upload($source, array_filter([
            'caption' => $caption,
            'published' => true,
            ...$params,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Unveröffentlichtes Foto (z. B. für Mehrfachfoto-Posts). Meta löscht es nach ca. 24 h,
     * sofern es nicht verwendet wird. Für geplante Posts temporary=true setzen.
     *
     * @param  array<string, mixed>  $params
     */
    public function uploadUnpublished(MediaSource $source, bool $temporary = false, array $params = []): Photo
    {
        return $this->upload($source, array_filter([
            'published' => false,
            'temporary' => $temporary ? true : null,
            ...$params,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function upload(MediaSource $source, array $params): Photo
    {
        $request = $this->client->request('POST', $this->client->pageId().'/photos', $params);

        $request = $source->isRemote()
            ? $request->withParam('url', $source->remoteUrl())
            : $request->attach('source', $source)->timeout($this->client->options()->uploadTimeout);

        return Photo::fromArray($this->client->send($request->retryable($source->isRemote()))->raw());
    }
}
