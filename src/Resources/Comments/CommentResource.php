<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Resources\Comments;

use Illuminate\Support\LazyCollection;
use Kuweh\MetaGraph\Data\Comment;
use Kuweh\MetaGraph\Graph\FieldSet;
use Kuweh\MetaGraph\Resources\Resource;

/**
 * Kommentare (/{object-id}/comments, /{comment-id}). Benötigt Engagement-Berechtigungen.
 */
final class CommentResource extends Resource
{
    /** @var list<string> */
    public const array COMMENT_FIELDS = ['id', 'message', 'from', 'created_time', 'is_hidden', 'like_count', 'comment_count'];

    /**
     * @param  'chronological'|'reverse_chronological'|null  $order
     * @param  'toplevel'|'stream'|null  $filter
     * @param  FieldSet|list<string>|null  $fields
     * @return LazyCollection<int, Comment>
     */
    public function list(string $objectId, ?string $order = null, ?string $filter = null, FieldSet|array|null $fields = null): LazyCollection
    {
        return $this->client->node($objectId)
            ->edge('comments')
            ->fields($fields ?? self::COMMENT_FIELDS)
            ->with(array_filter(['order' => $order, 'filter' => $filter]))
            ->cursor()
            ->map(static fn (array $item): Comment => Comment::fromArray($item));
    }

    /**
     * Antwortet auf einen Post oder Kommentar (Read-after-Write über fields).
     */
    public function reply(string $objectId, string $message, ?string $attachmentUrl = null): Comment
    {
        $response = $this->client->node($objectId)
            ->edge('comments')
            ->fields(['id', 'message', 'from', 'created_time'])
            ->post(array_filter(['message' => $message, 'attachment_url' => $attachmentUrl]));

        return Comment::fromArray($response->raw() + ['message' => $message]);
    }

    public function hide(string $commentId, bool $hidden = true): bool
    {
        return $this->succeeded($this->client->node($commentId)->post(['is_hidden' => $hidden]));
    }

    public function unhide(string $commentId): bool
    {
        return $this->hide($commentId, false);
    }

    public function delete(string $commentId): bool
    {
        return $this->succeeded($this->client->node($commentId)->delete());
    }
}
