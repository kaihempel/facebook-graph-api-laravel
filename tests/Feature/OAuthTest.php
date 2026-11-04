<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Auth\TokenType;
use KaiHempel\MetaGraph\Exceptions\ConfigurationException;
use KaiHempel\MetaGraph\Exceptions\InvalidStateException;
use KaiHempel\MetaGraph\Exceptions\LoginFailedException;
use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\Testing\GraphFake;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-31 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('builds the login url and stores the state in the session', function () {
    $url = MetaGraph::login()->redirectUrl(state: 'fixed-state');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://www.facebook.com/v26.0/dialog/oauth?')
        ->and($query)->toMatchArray([
            'client_id' => '1234567890',
            'redirect_uri' => 'https://example.test/meta/callback',
            'scope' => 'pages_show_list,pages_manage_posts',
            'state' => 'fixed-state',
            'response_type' => 'code',
        ])
        ->and(session('meta-graph.oauth_state.main'))->toBe('fixed-state');
});

it('generates a random state and supports custom scopes and redirects', function () {
    $response = MetaGraph::login()->redirect(['pages_show_list', 'publish_video'], extra: ['auth_type' => 'rerequest']);

    parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('pages_show_list,publish_video')
        ->and($query['auth_type'])->toBe('rerequest')
        ->and(strlen($query['state']))->toBe(40)
        ->and(session('meta-graph.oauth_state.main'))->toBe($query['state']);
});

it('exchanges the code after a valid state check', function () {
    $fake = MetaGraph::fake([
        'v26.0/oauth/access_token' => ['access_token' => 'short-lived', 'token_type' => 'bearer', 'expires_in' => 5183944],
    ]);
    $flow = MetaGraph::login();
    $flow->redirectUrl(state: 'abc');

    $token = $flow->handleCallback(Request::create('/callback', 'GET', ['code' => 'the-code', 'state' => 'abc']));

    expect($token->value)->toBe('short-lived')
        ->and($token->type)->toBe(TokenType::User)
        ->and($token->expiresAt?->toDateTimeString())->toBe(now()->addSeconds(5183944)->toDateTimeString())
        ->and(session()->has('meta-graph.oauth_state.main'))->toBeFalse();

    $fake->assertFetched('v26.0/oauth/access_token', fn (array $p) => $p['code'] === 'the-code'
        && $p['client_id'] === '1234567890'
        && $p['client_secret'] === 'test-app-secret'
        && $p['redirect_uri'] === 'https://example.test/meta/callback'
        && ! isset($p['access_token']));
});

it('rejects callbacks with an invalid or missing state', function (array $query) {
    MetaGraph::fake();
    $flow = MetaGraph::login();
    $flow->redirectUrl(state: 'expected');

    $flow->handleCallback(Request::create('/callback', 'GET', $query));
})->with([
    'wrong state' => [['code' => 'c', 'state' => 'forged']],
    'missing state' => [['code' => 'c']],
])->throws(InvalidStateException::class);

it('rejects replayed callbacks because the state is consumed', function () {
    MetaGraph::fake(['*' => ['access_token' => 't']]);
    $flow = MetaGraph::login();
    $flow->redirectUrl(state: 'once');
    $request = Request::create('/callback', 'GET', ['code' => 'c', 'state' => 'once']);

    $flow->handleCallback($request);
    $flow->handleCallback($request);
})->throws(InvalidStateException::class);

it('reports cancelled logins and missing codes', function () {
    $flow = MetaGraph::login();

    $flow->redirectUrl(state: 's1');
    try {
        $flow->handleCallback(Request::create('/cb', 'GET', ['state' => 's1', 'error' => 'access_denied', 'error_reason' => 'user_denied', 'error_description' => 'Permissions error']));
        $this->fail('Expected exception');
    } catch (LoginFailedException $e) {
        expect($e->error)->toBe('access_denied')
            ->and($e->errorReason)->toBe('user_denied')
            ->and($e->getMessage())->toBe('Permissions error');
    }

    $flow->redirectUrl(state: 's2');
    expect(fn () => $flow->handleCallback(Request::create('/cb', 'GET', ['state' => 's2'])))->toThrow(LoginFailedException::class);
});

it('requires a redirect uri', function () {
    config()->set('meta-graph.apps.main.redirect', null);

    MetaGraph::login()->redirectUrl();
})->throws(ConfigurationException::class);

it('exchanges a short lived token for a long lived token', function () {
    $fake = MetaGraph::fake(['v26.0/oauth/access_token' => ['access_token' => 'long-lived', 'expires_in' => 60 * 86400]]);

    $token = MetaGraph::tokens()->toLongLived(AccessToken::user('short', scopes: ['pages_show_list']));

    expect($token->value)->toBe('long-lived')
        ->and($token->scopes)->toBe(['pages_show_list'])
        ->and((int) round(now()->diffInDays($token->expiresAt)))->toBe(60)
        ->and(MetaGraph::tokens()->toLongLived('short')->value)->toBe('long-lived');

    $fake->assertFetched('v26.0/oauth/access_token', fn (array $p) => $p['grant_type'] === 'fb_exchange_token'
        && $p['fb_exchange_token'] === 'short'
        && $p['client_secret'] === 'test-app-secret');
});

it('reads page tokens with the long lived user token', function () {
    $fake = MetaGraph::fake([
        'v26.0/me/accounts' => ['data' => [['id' => '1', 'name' => 'Eins', 'category' => 'Cafe', 'tasks' => ['CREATE_CONTENT'], 'access_token' => 'pt']]],
        'v26.0/42/accounts' => ['data' => []],
    ]);

    $pages = MetaGraph::tokens()->pageTokens(AccessToken::user('long-lived'));
    MetaGraph::tokens()->pageTokens('long-lived', '42');

    expect($pages)->toHaveCount(1)
        ->and($pages->first()?->accessToken->value)->toBe('pt');
    $fake->assertFetched('v26.0/me/accounts', fn (array $p) => $p['access_token'] === 'long-lived');
});

it('debugs tokens with the app token', function () {
    $fake = MetaGraph::fake([
        'v26.0/debug_token' => GraphFake::response(fixture('debug_token')),
    ]);

    $info = MetaGraph::debugger()->inspect(AccessToken::page('page-token'));

    expect($info->isValid)->toBeTrue()
        ->and($info->type)->toBe(TokenType::Page)
        ->and($info->appId)->toBe('1234567890')
        ->and($info->userId)->toBe('10000000001')
        ->and($info->profileId)->toBe('111')
        ->and($info->neverExpires())->toBeTrue()
        ->and($info->dataAccessExpiresAt?->getTimestamp())->toBe(1801000000)
        ->and($info->hasScope('pages_manage_posts'))->toBeTrue()
        ->and($info->missingScopes(['pages_manage_posts', 'publish_video']))->toBe(['publish_video'])
        ->and($info->raw())->toHaveKey('scopes');

    $fake->assertFetched('v26.0/debug_token', fn (array $p) => $p['input_token'] === 'page-token'
        && $p['access_token'] === '1234567890|test-app-secret');
});
