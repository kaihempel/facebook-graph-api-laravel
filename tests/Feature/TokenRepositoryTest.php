<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Auth\ArrayTokenRepository;
use KaiHempel\MetaGraph\Auth\EloquentTokenRepository;
use KaiHempel\MetaGraph\Contracts\TokenRepository;
use KaiHempel\MetaGraph\Data\UploadSession;
use KaiHempel\MetaGraph\Events\PageConnected;
use KaiHempel\MetaGraph\Events\PageDisconnected;
use KaiHempel\MetaGraph\Exceptions\ConfigurationException;
use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\Uploads\ArrayUploadSessionStore;
use KaiHempel\MetaGraph\Uploads\EloquentUploadSessionStore;

it('stores tokens encrypted with eloquent', function (string $repository) {
    /** @var TokenRepository $tokens */
    $tokens = new $repository;
    $expires = CarbonImmutable::parse('2026-12-30 10:00:00');

    $tokens->store('user', '42', AccessToken::user('user-secret', $expires, ['pages_show_list']));
    $tokens->store('page', '111', AccessToken::page('page-secret'));
    $tokens->store('page', '111', AccessToken::page('page-secret-2'));

    $user = $tokens->find('user', '42');

    expect($user?->value)->toBe('user-secret')
        ->and($user?->type->value)->toBe('user')
        ->and($user?->scopes)->toBe(['pages_show_list'])
        ->and($user?->expiresAt?->toDateTimeString())->toBe('2026-12-30 10:00:00')
        ->and($tokens->find('page', '111')?->value)->toBe('page-secret-2')
        ->and($tokens->find('page', '404'))->toBeNull()
        ->and(iterator_to_array($tokens->all(), false))->toHaveCount(2);

    $tokens->markValidated('page', '111');
    $stored = collect($tokens->all())->firstWhere('ownerId', '111');
    expect($stored?->lastValidatedAt)->not->toBeNull();

    $tokens->forget('page', '111');
    expect($tokens->find('page', '111'))->toBeNull();
})->with([EloquentTokenRepository::class, ArrayTokenRepository::class]);

it('never stores tokens in plain text', function () {
    (new EloquentTokenRepository)->store('page', '1', AccessToken::page('plain-page-token'));

    expect(DB::table('meta_graph_tokens')->value('token'))->not->toContain('plain-page-token');
});

it('connects and disconnects pages via the configured repository', function () {
    Event::fake();
    $fake = MetaGraph::fake(['v26.0/111' => ['id' => '111']]);

    MetaGraph::connectPage($this->page());
    MetaGraph::asStoredPage('111')->node('111')->get();
    MetaGraph::disconnectPage('111');

    Event::assertDispatched(PageConnected::class, fn (PageConnected $e) => $e->page->id === '111' && $e->app === 'main');
    Event::assertDispatched(PageDisconnected::class, fn (PageDisconnected $e) => $e->pageId === '111');
    $fake->assertFetched('v26.0/111', fn (array $p) => $p['access_token'] === 'page-token-111');

    expect(fn () => MetaGraph::asStoredPage('111'))->toThrow(ConfigurationException::class);
});

it('persists upload sessions', function (string $store) {
    $sessions = new $store;
    $sessions->store('k', new UploadSession('upload:1', 'a.mp4', 10, 'video/mp4'));
    $sessions->store('k', $sessions->find('k')->withHandle('H'));

    expect($sessions->find('k')?->handle)->toBe('H')
        ->and($sessions->find('k')?->isComplete())->toBeTrue()
        ->and($sessions->find('k')?->fileLength)->toBe(10);

    $sessions->forget('k');
    expect($sessions->find('k'))->toBeNull();
})->with([EloquentUploadSessionStore::class, ArrayUploadSessionStore::class]);
