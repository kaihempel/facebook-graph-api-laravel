<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Graph;

use Illuminate\Support\LazyCollection;
use Kuweh\MetaGraph\Data\CursorPage;

/**
 * Folgt paging.next einer Edge. Ein konfigurierbares max_pages schützt vor Endlosschleifen.
 */
final readonly class Paginator
{
    /** Parameter aus paging.next, die der Client selbst setzt. */
    private const array IGNORED_PARAMS = ['access_token', 'appsecret_proof'];

    public function __construct(
        private GraphClient $client,
        private int $maxPages = 50,
    ) {}

    /**
     * @return CursorPage<array<array-key, mixed>>
     */
    public function page(GraphRequest $request): CursorPage
    {
        return CursorPage::fromResponse($this->client->send($request)->raw());
    }

    /**
     * @return LazyCollection<int, array<array-key, mixed>>
     */
    public function cursor(GraphRequest $request, ?int $maxPages = null): LazyCollection
    {
        $maxPages ??= $this->maxPages;

        return LazyCollection::make(function () use ($request, $maxPages) {
            $current = $request;

            for ($page = 1; $maxPages <= 0 || $page <= $maxPages; $page++) {
                $result = $this->page($current);

                foreach ($result->items as $item) {
                    yield $item;
                }

                $next = $result->nextUrl();

                if (! $result->hasMore || $next === null || $result->isEmpty()) {
                    return;
                }

                $current = self::nextRequest($request, $next);
            }
        });
    }

    /**
     * Übernimmt die Paging-Parameter (after, before, since, until, offset, ...) aus
     * paging.next in den ursprünglichen Request. Token und appsecret_proof setzt der Client selbst.
     */
    public static function nextRequest(GraphRequest $request, string $nextUrl): GraphRequest
    {
        $query = [];
        parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $query);

        $params = array_diff_key($query, array_flip(self::IGNORED_PARAMS));

        if (isset($params['before'], $params['after'])) {
            unset($params['before']);
        }

        /** @var array<string, mixed> $params */
        return $request->withoutParam('before')->withParams($params);
    }
}
