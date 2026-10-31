<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Contracts\TokenRepository;
use Kuweh\MetaGraph\Events\TokenExpiringSoon;
use Kuweh\MetaGraph\Events\TokenInvalidated;
use Kuweh\MetaGraph\Facades\MetaGraph;
use Kuweh\MetaGraph\Testing\GraphFake;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-31 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('checks stored tokens and fires expiry and invalidation events', function () {
    Event::fake([TokenExpiringSoon::class, TokenInvalidated::class]);
    $repo = MetaGraph::tokenRepository();
    $repo->store('user', 'soon', AccessToken::user('soon-token', now()->addDays(3)));
    $repo->store('user', 'fine', AccessToken::user('fine-token', now()->addDays(50)));
    $repo->store('page', 'dead', AccessToken::page('dead-token'));
    $repo->store('page', 'broken', AccessToken::page('broken-token'));

    MetaGraph::fake([
        'v26.0/debug_token' => fn (array $p) => match ($p['input_token']) {
            'soon-token' => GraphFake::response(['data' => ['is_valid' => true, 'type' => 'USER', 'expires_at' => now()->addDays(3)->getTimestamp()]]),
            'fine-token' => GraphFake::response(['data' => ['is_valid' => true, 'type' => 'USER', 'expires_at' => now()->addDays(50)->getTimestamp()]]),
            'dead-token' => GraphFake::response(['data' => ['is_valid' => false, 'error' => ['message' => 'Session has expired']]]),
            default => GraphFake::error(100, 'Invalid token'),
        },
    ]);

    $this->artisan('meta-graph:tokens:check')
        ->expectsTable(['Owner type', 'Owner ID', 'Token type', 'Status'], [
            ['user', 'soon', 'user', 'expires 2026-11-03 12:00:00'],
            ['user', 'fine', 'user', 'ok'],
            ['page', 'dead', 'page', 'invalid'],
            ['page', 'broken', 'page', 'error: Invalid token'],
        ])
        ->assertSuccessful();

    Event::assertDispatched(TokenExpiringSoon::class, fn ($e) => $e->ownerId === 'soon');
    Event::assertDispatched(TokenInvalidated::class, fn ($e) => $e->ownerId === 'dead' && $e->ownerType === 'page');
});

it('fails the token check for repositories that cannot list tokens', function () {
    app()->instance(TokenRepository::class, new class implements TokenRepository
    {
        public function find(string $ownerType, string $ownerId): ?AccessToken
        {
            return null;
        }

        public function store(string $ownerType, string $ownerId, AccessToken $token): void {}

        public function forget(string $ownerType, string $ownerId): void {}
    });

    $this->artisan('meta-graph:tokens:check')->assertFailed();
});

it('debugs a token', function () {
    MetaGraph::fake(['v26.0/debug_token' => GraphFake::response(fixture('debug_token'))]);

    $this->artisan('meta-graph:token:debug', ['token' => 'page-token'])
        ->expectsOutputToContain('pages_manage_posts')
        ->assertSuccessful();

    $this->artisan('meta-graph:token:debug')
        ->expectsQuestion('Access token', 'asked-token')
        ->assertSuccessful();
});

it('fails debugging invalid tokens', function () {
    MetaGraph::fake(['v26.0/debug_token' => GraphFake::error(190, 'Invalid')]);

    $this->artisan('meta-graph:token:debug', ['token' => 'x', '--app' => 'kunde-b'])->assertFailed();
});

it('lists and connects pages of a stored user', function () {
    MetaGraph::fake(['v26.0/me/accounts' => ['data' => [['id' => '1', 'name' => 'Eins', 'category' => 'Cafe', 'tasks' => ['CREATE_CONTENT', 'MANAGE'], 'access_token' => 'pt1']]]]);
    MetaGraph::tokenRepository()->store('user', '42', AccessToken::user('long-lived'));

    $this->artisan('meta-graph:pages', ['--user' => '42', '--connect' => true])
        ->expectsTable(['ID', 'Name', 'Category', 'Tasks'], [['1', 'Eins', 'Cafe', 'CREATE_CONTENT, MANAGE']])
        ->assertSuccessful();

    expect(MetaGraph::tokenRepository()->find('page', '1')?->value)->toBe('pt1');
});

it('fails listing pages on graph errors', function () {
    MetaGraph::fake(['v26.0/me/accounts' => GraphFake::error(190, 'Expired', 463)]);

    $this->artisan('meta-graph:pages')->expectsQuestion('User ID or user access token', 'token')->assertFailed();
});

it('subscribes app wide webhook fields', function () {
    $fake = MetaGraph::fake(['v26.0/1234567890/subscriptions' => ['success' => true]]);

    $this->artisan('meta-graph:webhooks:subscribe', ['--fields' => 'feed'])->assertSuccessful();

    $fake->assertPosted('v26.0/1234567890/subscriptions', fn (array $p) => $p['object'] === 'page'
        && $p['fields'] === 'feed'
        && $p['verify_token'] === 'verify-me'
        && str_ends_with($p['callback_url'], '/meta-graph/webhook')
        && $p['access_token'] === '1234567890|test-app-secret');
});

it('requires a verify token and reports graph errors when subscribing', function () {
    MetaGraph::fake(['*' => GraphFake::error(200, 'Requires pages_manage_metadata permission')]);
    $this->artisan('meta-graph:webhooks:subscribe', ['--callback' => 'https://x.test/hook'])->assertFailed();

    config()->set('meta-graph.webhooks.verify_token', null);
    $this->artisan('meta-graph:webhooks:subscribe')->assertFailed();
});

it('checks the configured graph version', function (string $version, ?string $expiry, string $now, string $output, int $exit) {
    CarbonImmutable::setTestNow($now);
    config()->set('meta-graph.version', $version);
    if ($expiry !== null) {
        config()->set('meta-graph.version_expirations', [$version => $expiry]);
    }

    $this->artisan('meta-graph:version:check')->expectsOutputToContain($output)->assertExitCode($exit);
})->with([
    'current' => ['v26.0', null, '2026-10-31', 'no published expiry date', 0],
    'supported' => ['v25.0', null, '2026-10-31', 'supported until 2028-07-29', 0],
    'expiring' => ['v25.0', null, '2028-06-01', 'expires on 2028-07-29', 0],
    'expired' => ['v19.0', '2026-05-21', '2026-10-31', 'expired on 2026-05-21', 1],
    'unknown' => ['v1.0', null, '2026-10-31', 'unknown', 0],
]);

it('publishes config and migrations with the install command', function () {
    $cleanup = function () {
        File::delete(array_merge(
            [config_path('meta-graph.php')],
            glob(database_path('migrations/*meta_graph*')) ?: [],
        ));
    };

    try {
        $this->artisan('meta-graph:install')
            ->expectsConfirmation('Would you like to run the migrations now?', 'no')
            ->assertSuccessful();

        expect(file_exists(config_path('meta-graph.php')))->toBeTrue()
            ->and(glob(database_path('migrations/*create_meta_graph_tokens_table.php')))->toHaveCount(1);
    } finally {
        $cleanup();
    }
});
