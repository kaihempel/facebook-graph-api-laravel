<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Resources\Posts;

use KaiHempel\MetaGraph\Contracts\MediaSource;

/**
 * Unveränderlicher, serialisierbarer Entwurf eines Feed-Posts (queue-tauglich).
 */
final readonly class PostDraft
{
    /**
     * @param  list<MediaSource>  $photos
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public ?string $message = null,
        public ?string $link = null,
        public array $photos = [],
        public array $params = [],
    ) {}

    public static function make(?string $message = null): self
    {
        return new self($message);
    }

    public function message(string $message): self
    {
        return new self($message, $this->link, $this->photos, $this->params);
    }

    public function link(string $url): self
    {
        return new self($this->message, $url, $this->photos, $this->params);
    }

    /**
     * @param  list<MediaSource>  $photos
     */
    public function photos(array $photos): self
    {
        return new self($this->message, $this->link, $photos, $this->params);
    }

    public function photo(MediaSource $photo): self
    {
        return new self($this->message, $this->link, [...$this->photos, $photo], $this->params);
    }

    /**
     * Zusätzliche Graph-Parameter (z. B. place, tags, targeting).
     *
     * @param  array<string, mixed>  $params
     */
    public function with(array $params): self
    {
        return new self($this->message, $this->link, $this->photos, array_merge($this->params, $params));
    }

    public function hasPhotos(): bool
    {
        return $this->photos !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return array_filter([
            'message' => $this->message,
            'link' => $this->link,
            ...$this->params,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
