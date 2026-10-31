<?php

declare(strict_types=1);

use Kuweh\MetaGraph\Facades\MetaGraph;
use Kuweh\MetaGraph\Graph\GraphRequest;
use Kuweh\MetaGraph\Graph\Paginator;
use Kuweh\MetaGraph\Testing\GraphFake;

it('returns a cursor page', function () {
    MetaGraph::fake([
        'v26.0/111/feed' => GraphFake::paginated([[['id' => '1'], ['id' => '2']], [['id' => '3']]]),
    ]);

    $page = MetaGraph::asPage($this->page())->node('111')->edge('feed')->paginate(limit: 2);

    expect($page->items)->toHaveCount(2)
        ->and($page)->toHaveCount(2)
        ->and($page->hasMore)->toBeTrue()
        ->and($page->nextCursor)->toBe('cursor1')
        ->and($page->previousCursor)->toBe('cursor0')
        ->and($page->map(fn (array $i) => $i['id'])->items)->toBe(['1', '2'])
        ->and(iterator_to_array($page))->toHaveCount(2);
});

it('follows paging.next lazily and keeps the original parameters', function () {
    $fake = MetaGraph::fake([
        'v26.0/111/feed' => GraphFake::paginated([
            [['id' => '1'], ['id' => '2']],
            [['id' => '3']],
            [['id' => '4']],
        ]),
    ]);

    $ids = MetaGraph::asPage($this->page())->node('111')->edge('feed')->fields('id')->cursor()->pluck('id')->all();

    expect($ids)->toBe(['1', '2', '3', '4']);
    $fake->assertSentCount(3);
    $fake->assertFetched('v26.0/111/feed', fn (array $p) => ($p['after'] ?? null) === 'cursor2'
        && $p['fields'] === 'id'
        && $p['access_token'] === 'page-token-111');
});

it('stops after max_pages', function () {
    config()->set('meta-graph.pagination.max_pages', 2);
    $fake = MetaGraph::fake([
        'v26.0/111/feed' => GraphFake::paginated([[['id' => '1']], [['id' => '2']], [['id' => '3']]]),
    ]);

    $ids = MetaGraph::asPage($this->page())->node('111')->edge('feed')->cursor()->pluck('id')->all();

    expect($ids)->toBe(['1', '2']);
    $fake->assertSentCount(2);
});

it('passes the paging parameters of the next url but never its token', function () {
    $request = GraphRequest::get('111/feed', ['fields' => 'id', 'before' => 'x']);

    $next = Paginator::nextRequest($request, 'https://graph.facebook.com/v26.0/111/feed?access_token=leak&appsecret_proof=p&until=1700000000&__paging_token=abc&limit=25');

    expect($next->params)->toBe(['fields' => 'id', 'until' => '1700000000', '__paging_token' => 'abc', 'limit' => '25']);
});
