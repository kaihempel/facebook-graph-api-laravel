<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Resources\Insights;

use BackedEnum;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Kuweh\MetaGraph\Data\InsightMetric;
use Kuweh\MetaGraph\Graph\NodeQuery;
use Kuweh\MetaGraph\Resources\Resource;

/**
 * Basis-Insights für Seiten (/{page-id}/insights) und Posts (/{post-id}/insights).
 */
final class InsightResource extends Resource
{
    /**
     * @param  list<PageMetric|string>  $metrics
     * @return Collection<int, InsightMetric>
     */
    public function page(
        array $metrics,
        ?CarbonPeriod $range = null,
        InsightPeriod|string|null $period = InsightPeriod::Day,
        ?string $pageId = null,
    ): Collection {
        return $this->fetch($this->client->node($pageId ?? $this->client->pageId()), $metrics, $range, $period);
    }

    /**
     * @param  list<PostMetric|string>  $metrics
     * @return Collection<int, InsightMetric>
     */
    public function post(
        string $postId,
        array $metrics,
        InsightPeriod|string|null $period = InsightPeriod::Lifetime,
    ): Collection {
        return $this->fetch($this->client->node($postId), $metrics, null, $period);
    }

    /**
     * @param  list<BackedEnum|string>  $metrics
     * @return Collection<int, InsightMetric>
     */
    private function fetch(NodeQuery $node, array $metrics, ?CarbonPeriod $range, InsightPeriod|string|null $period): Collection
    {
        $names = array_map(static fn (BackedEnum|string $m): string => $m instanceof BackedEnum ? (string) $m->value : $m, $metrics);

        $params = array_filter([
            'metric' => implode(',', $names),
            'period' => $period instanceof InsightPeriod ? $period->value : $period,
            'since' => $range?->getStartDate(),
            'until' => $range?->getEndDate(),
        ], static fn (mixed $v): bool => $v !== null);

        return $node->edge('insights')
            ->with($params)
            ->cursor()
            ->map(static fn (array $item): InsightMetric => InsightMetric::fromArray($item))
            ->values()
            ->collect();
    }
}
