<?php

declare(strict_types=1);

use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\Testing\GraphFake;

it('lists connected pages with page tokens and tasks', function () {
    $fake = MetaGraph::fake([
        'v26.0/me/accounts' => GraphFake::paginated([
            [['id' => '1', 'name' => 'Eins', 'category' => 'Cafe', 'tasks' => ['CREATE_CONTENT', 'MANAGE'], 'access_token' => 'pt1']],
            [['id' => '2', 'name' => 'Zwei', 'tasks' => ['ANALYZE'], 'access_token' => 'pt2']],
        ]),
    ]);

    $pages = MetaGraph::asUser('user-token')->pages()->connected();

    expect($pages)->toHaveCount(2)
        ->and($pages[0]->id)->toBe('1')
        ->and($pages[0]->accessToken->value)->toBe('pt1')
        ->and($pages[0]->accessToken->type->value)->toBe('page')
        ->and($pages[0]->canCreateContent())->toBeTrue()
        ->and($pages[1]->canCreateContent())->toBeFalse()
        ->and($pages[1]->category)->toBeNull()
        ->and($pages[0]->raw()['name'])->toBe('Eins');

    $fake->assertFetched('v26.0/me/accounts', fn (array $p) => $p['fields'] === 'id,name,category,tasks,access_token');
});

it('finds a page', function () {
    MetaGraph::fake(['v26.0/111' => ['id' => '111', 'name' => 'Testseite', 'fan_count' => 10, 'followers_count' => 12, 'link' => 'https://fb.com/111']]);

    $page = MetaGraph::asPage($this->page())->pages()->find('111');

    expect($page->name)->toBe('Testseite')
        ->and($page->fanCount)->toBe(10)
        ->and($page->followersCount)->toBe(12)
        ->and($page->link)->toBe('https://fb.com/111')
        ->and($page->raw())->toHaveKey('id');
});

it('subscribes and unsubscribes the app to page webhooks with the page token', function () {
    $fake = MetaGraph::fake([
        'v26.0/111/subscribed_apps' => fn (array $p, $r) => $r->method === 'GET'
            ? GraphFake::response(['data' => [['id' => '1234567890', 'subscribed_fields' => ['feed']]]])
            : GraphFake::response(['success' => true]),
    ]);

    $pages = MetaGraph::asUser('user-token')->pages();

    expect($pages->subscribeApp($this->page(), ['feed', 'mention']))->toBeTrue()
        ->and($pages->unsubscribeApp($this->page()))->toBeTrue()
        ->and(MetaGraph::asPage($this->page())->pages()->subscribedApps())->toHaveCount(1);

    $fake->assertPosted('v26.0/111/subscribed_apps', fn (array $p) => $p['subscribed_fields'] === 'feed,mention' && $p['access_token'] === 'page-token-111');
    $fake->assertDeleted('v26.0/111/subscribed_apps');
});

it('uses me as page id when only a page token string is known', function () {
    $fake = MetaGraph::fake(['v26.0/me/subscribed_apps' => ['success' => true]]);

    MetaGraph::asPage('some-page-token')->pages()->subscribeApp();

    $fake->assertPosted('v26.0/me/subscribed_apps', fn (array $p) => $p['subscribed_fields'] === 'feed');
});
