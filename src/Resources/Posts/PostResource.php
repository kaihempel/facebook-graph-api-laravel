<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Resources\Posts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use KaiHempel\MetaGraph\Contracts\MediaSource;
use KaiHempel\MetaGraph\Data\Post;
use KaiHempel\MetaGraph\Events\PostFailed;
use KaiHempel\MetaGraph\Events\PostPublished;
use KaiHempel\MetaGraph\Events\PostScheduled;
use KaiHempel\MetaGraph\Graph\FieldSet;
use KaiHempel\MetaGraph\Resources\Resource;
use KaiHempel\MetaGraph\Support\Dispatch;
use Throwable;

/**
 * Feed-Posts einer Seite (/{page-id}/feed): Text, Link, Fotos, geplante Posts.
 */
final class PostResource extends Resource
{
    /** @var list<string> */
    public const array POST_FIELDS = ['id', 'message', 'created_time', 'is_published', 'scheduled_publish_time', 'permalink_url'];

    public function create(PostDraft|string $draft): Post
    {
        return $this->publish(self::draft($draft), null);
    }

    /**
     * Plant einen Post (published=false + scheduled_publish_time). Mit Fotos werden diese
     * zunächst unveröffentlicht (temporary=true) hochgeladen und per attached_media verknüpft.
     */
    public function schedule(PostDraft|string $draft, DateTimeInterface $publishAt): Post
    {
        $publishAt = CarbonImmutable::instance($publishAt);
        $this->assertSchedulable($publishAt);

        return $this->publish(self::draft($draft), $publishAt);
    }

    /**
     * Mehrfachfoto-Post: Fotos unveröffentlicht hochladen, dann Feed-Post mit allen media_fbid.
     *
     * @param  list<MediaSource>  $photos
     */
    public function withPhotos(array $photos, ?string $message = null, ?DateTimeInterface $publishAt = null): Post
    {
        $draft = PostDraft::make($message)->photos($photos);

        return $publishAt === null ? $this->create($draft) : $this->schedule($draft, $publishAt);
    }

    /**
     * Updates laufen per POST auf den Node.
     *
     * @param  PostDraft|array<string, mixed>|string  $changes
     */
    public function update(string $postId, PostDraft|array|string $changes): bool
    {
        $params = match (true) {
            is_string($changes) => ['message' => $changes],
            $changes instanceof PostDraft => $changes->toParams(),
            default => $changes,
        };

        return $this->succeeded($this->client->node($postId)->post($params));
    }

    public function delete(string $postId): bool
    {
        return $this->succeeded($this->client->node($postId)->delete());
    }

    /**
     * Veröffentlichte und unveröffentlichte Posts (Feld is_published).
     *
     * @param  FieldSet|list<string>|null  $fields
     * @return LazyCollection<int, Post>
     */
    public function feed(
        ?DateTimeInterface $since = null,
        ?DateTimeInterface $until = null,
        FieldSet|array|null $fields = null,
        ?int $limit = null,
    ): LazyCollection {
        $query = $this->client->node($this->client->pageId())->edge('feed')->fields($fields ?? self::POST_FIELDS);

        if ($since !== null) {
            $query = $query->since($since);
        }

        if ($until !== null) {
            $query = $query->until($until);
        }

        if ($limit !== null) {
            $query = $query->limit($limit);
        }

        return $query->cursor()->map(static fn (array $item): Post => Post::fromArray($item));
    }

    /**
     * Geplante Posts der Seite.
     *
     * @param  FieldSet|list<string>|null  $fields
     * @return LazyCollection<int, Post>
     */
    public function scheduled(FieldSet|array|null $fields = null): LazyCollection
    {
        return $this->client->node($this->client->pageId())
            ->edge('scheduled_posts')
            ->fields($fields ?? self::POST_FIELDS)
            ->cursor()
            ->map(static fn (array $item): Post => Post::fromArray($item));
    }

    public function find(string $postId): Post
    {
        return Post::fromArray($this->client->node($postId)->fields(self::POST_FIELDS)->get()->raw());
    }

    private function publish(PostDraft $draft, ?CarbonImmutable $publishAt): Post
    {
        $pageId = $this->client->pageId();
        $uploaded = [];

        try {
            $params = $draft->toParams();

            if ($draft->hasPhotos()) {
                $photos = $this->client->photos();

                foreach ($draft->photos as $photo) {
                    $uploaded[] = $photos->uploadUnpublished($photo, temporary: $publishAt !== null)->id;
                }

                $params['attached_media'] = array_map(static fn (string $id): array => ['media_fbid' => $id], $uploaded);
            }

            if ($publishAt !== null) {
                $params['published'] = false;
                $params['scheduled_publish_time'] = $publishAt;
            }

            $response = $this->client->node($pageId)->edge('feed')->fields(self::POST_FIELDS)->post($params);
            $data = $response->raw() + ['is_published' => $publishAt === null];
            $post = Post::fromArray($data, $publishAt);
        } catch (Throwable $e) {
            Dispatch::event(new PostFailed($draft, $pageId, $e, $uploaded));

            throw $e;
        }

        Dispatch::event($publishAt === null ? new PostPublished($post, $pageId) : new PostScheduled($post, $pageId));

        return $post;
    }

    private function assertSchedulable(CarbonImmutable $publishAt): void
    {
        $options = $this->client->options();
        $now = CarbonImmutable::now();

        if ($publishAt->lessThan($now->addMinutes($options->minScheduleLeadMinutes))) {
            throw new InvalidArgumentException("Scheduled posts must be at least {$options->minScheduleLeadMinutes} minutes in the future.");
        }

        if ($options->maxScheduleLeadDays > 0 && $publishAt->greaterThan($now->addDays($options->maxScheduleLeadDays))) {
            throw new InvalidArgumentException("Scheduled posts must be at most {$options->maxScheduleLeadDays} days in the future.");
        }
    }

    private static function draft(PostDraft|string $draft): PostDraft
    {
        return is_string($draft) ? PostDraft::make($draft) : $draft;
    }
}
