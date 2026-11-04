<?php

declare(strict_types=1);

use Carbon\CarbonPeriod;
use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\Resources\Insights\InsightPeriod;
use KaiHempel\MetaGraph\Resources\Insights\PageMetric;
use KaiHempel\MetaGraph\Resources\Insights\PostMetric;

it('lists, replies, hides and deletes comments', function () {
    $fake = MetaGraph::fake([
        'v26.0/111_1/comments' => fn (array $p, $r) => $r->method === 'GET'
            ? ['data' => [['id' => 'c1', 'message' => 'Toll!', 'from' => ['id' => 'u1', 'name' => 'Erika'], 'created_time' => '2026-10-30T10:00:00+0000', 'is_hidden' => false, 'like_count' => 3]]]
            : ['id' => 'c2', 'created_time' => '2026-10-31T10:00:00+0000'],
        'v26.0/c1' => ['success' => true],
    ]);

    $comments = MetaGraph::asPage($this->page())->comments();
    $list = $comments->list('111_1', order: 'reverse_chronological', filter: 'toplevel')->all();
    $reply = $comments->reply('111_1', 'Danke!');

    expect($list[0]->message)->toBe('Toll!')
        ->and($list[0]->fromName)->toBe('Erika')
        ->and($list[0]->fromId)->toBe('u1')
        ->and($list[0]->isHidden)->toBeFalse()
        ->and($list[0]->likeCount)->toBe(3)
        ->and($list[0]->raw())->toHaveKey('from')
        ->and($reply->id)->toBe('c2')
        ->and($reply->message)->toBe('Danke!')
        ->and($comments->hide('c1'))->toBeTrue()
        ->and($comments->unhide('c1'))->toBeTrue()
        ->and($comments->delete('c1'))->toBeTrue();

    $fake->assertFetched('v26.0/111_1/comments', fn (array $p) => $p['order'] === 'reverse_chronological' && $p['filter'] === 'toplevel');
    $fake->assertPosted('v26.0/c1', fn (array $p) => $p['is_hidden'] === 'true');
    $fake->assertPosted('v26.0/c1', fn (array $p) => $p['is_hidden'] === 'false');
    $fake->assertDeleted('v26.0/c1');
});

it('reads page insights for a period', function () {
    $fake = MetaGraph::fake([
        'v26.0/111/insights' => ['data' => [[
            'name' => 'page_media_view',
            'period' => 'day',
            'title' => 'Media views',
            'description' => 'Views',
            'values' => [
                ['value' => 10, 'end_time' => '2026-10-29T07:00:00+0000'],
                ['value' => 15, 'end_time' => '2026-10-30T07:00:00+0000'],
            ],
        ]]],
    ]);

    $metrics = MetaGraph::asPage($this->page())->insights()->page(
        [PageMetric::MediaView, 'page_follows'],
        CarbonPeriod::create('2026-10-01', '2026-10-31'),
    );

    expect($metrics)->toHaveCount(1)
        ->and($metrics[0]->name)->toBe('page_media_view')
        ->and($metrics[0]->total())->toBe(25)
        ->and($metrics[0]->latest()?->value)->toBe(15)
        ->and($metrics[0]->latest()?->endTime?->toDateString())->toBe('2026-10-30')
        ->and($metrics[0]->raw())->toHaveKey('title');

    $fake->assertFetched('v26.0/111/insights', fn (array $p) => $p['metric'] === 'page_media_view,page_follows'
        && $p['period'] === 'day'
        && $p['since'] === (string) strtotime('2026-10-01 00:00:00')
        && $p['until'] === (string) strtotime('2026-10-31 00:00:00'));
});

it('reads post insights including breakdown values', function () {
    $fake = MetaGraph::fake([
        'v26.0/111_1/insights' => ['data' => [[
            'name' => 'post_reactions_by_type_total',
            'period' => 'lifetime',
            'values' => [['value' => ['like' => 3, 'love' => 2]]],
        ]]],
    ]);

    $metrics = MetaGraph::asPage($this->page())->insights()->post('111_1', [PostMetric::ReactionsByTypeTotal], InsightPeriod::Lifetime);

    expect($metrics[0]->total())->toBe(5)
        ->and($metrics[0]->values[0]->value)->toBe(['like' => 3, 'love' => 2]);

    $fake->assertFetched('*/insights', fn (array $p) => $p['period'] === 'lifetime');
});
