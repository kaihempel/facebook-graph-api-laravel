<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Resources\Pages;

use Illuminate\Support\Collection;
use KaiHempel\MetaGraph\Data\Page;
use KaiHempel\MetaGraph\Data\PageConnection;
use KaiHempel\MetaGraph\Graph\FieldSet;
use KaiHempel\MetaGraph\Graph\GraphClient;
use KaiHempel\MetaGraph\Resources\Resource;

final class PageResource extends Resource
{
    /** @var list<string> */
    public const array CONNECTION_FIELDS = ['id', 'name', 'category', 'tasks', 'access_token'];

    /** @var list<string> */
    public const array PAGE_FIELDS = ['id', 'name', 'category', 'fan_count', 'followers_count', 'link'];

    /**
     * Seiten des Users inklusive Page Token und Tasks (GET /{user-id}/accounts, paginiert).
     *
     * @return Collection<int, PageConnection>
     */
    public function connected(string $userId = 'me'): Collection
    {
        return $this->client->node($userId)
            ->edge('accounts')
            ->fields(self::CONNECTION_FIELDS)
            ->cursor()
            ->map(static fn (array $item): PageConnection => PageConnection::fromArray($item))
            ->values()
            ->collect();
    }

    /**
     * @param  FieldSet|list<string>|null  $fields
     */
    public function find(string $id, FieldSet|array|null $fields = null): Page
    {
        return Page::fromArray(
            $this->client->node($id)->fields($fields ?? self::PAGE_FIELDS)->get()->raw(),
        );
    }

    /**
     * Abonniert Webhook-Felder für die Seite (setzt pages_manage_metadata voraus).
     *
     * @param  list<string>  $fields
     */
    public function subscribeApp(PageConnection|string|null $page = null, array $fields = ['feed']): bool
    {
        [$client, $pageId] = $this->forPage($page);

        return $this->succeeded(
            $client->node($pageId)->edge('subscribed_apps')->post(['subscribed_fields' => implode(',', $fields)]),
        );
    }

    public function unsubscribeApp(PageConnection|string|null $page = null): bool
    {
        [$client, $pageId] = $this->forPage($page);

        return $this->succeeded($client->node($pageId)->edge('subscribed_apps')->delete());
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function subscribedApps(PageConnection|string|null $page = null): array
    {
        [$client, $pageId] = $this->forPage($page);

        return $client->node($pageId)->edge('subscribed_apps')->get()->data();
    }

    /**
     * @return array{0: GraphClient, 1: string}
     */
    private function forPage(PageConnection|string|null $page): array
    {
        if ($page instanceof PageConnection) {
            return [$this->client->withToken($page->accessToken, $page->id), $page->id];
        }

        return [$this->client, $page ?? $this->client->pageId()];
    }
}
