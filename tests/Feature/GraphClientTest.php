<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Auth\AppSecretProof;
use Kuweh\MetaGraph\Events\TokenInvalidated;
use Kuweh\MetaGraph\Events\UserActionRequired;
use Kuweh\MetaGraph\Exceptions\AuthenticationException;
use Kuweh\MetaGraph\Exceptions\DuplicatePostException;
use Kuweh\MetaGraph\Exceptions\GraphException;
use Kuweh\MetaGraph\Exceptions\UserActionRequiredException;
use Kuweh\MetaGraph\Facades\MetaGraph;
use Kuweh\MetaGraph\Graph\FieldSet;
use Kuweh\MetaGraph\Graph\GraphClient;
use Kuweh\MetaGraph\Graph\GraphRequest;
use Kuweh\MetaGraph\GraphManager;
use Kuweh\MetaGraph\Testing\GraphFake;
use Kuweh\MetaGraph\Tests\TestCase;

it('reads a node with fields, version, token and appsecret_proof', function () {
    $fake = MetaGraph::fake([
        'v26.0/111' => GraphFake::response(['id' => '111', 'name' => 'Testseite', 'fan_count' => 42]),
    ]);

    $response = MetaGraph::asPage($this->page())
        ->node('111')
        ->fields(['id', 'name', 'fan_count'])
        ->get();

    expect($response->json('name'))->toBe('Testseite')
        ->and($response->json('fan_count'))->toBe(42)
        ->and($response->id())->toBe('111')
        ->and($response->successful())->toBeTrue();

    $fake->assertFetched('v26.0/111', fn (array $params) => $params['fields'] === 'id,name,fan_count'
        && $params['access_token'] === 'page-token-111'
        && $params['appsecret_proof'] === AppSecretProof::make('page-token-111', TestCase::APP_SECRET));
});

it('posts to edges with read-after-write fields and deletes nodes', function () {
    $fake = MetaGraph::fake([
        'v26.0/111/feed' => ['id' => '111_222', 'message' => 'Hallo', 'created_time' => '2026-10-31T10:00:00+0000'],
        'v26.0/111_222' => ['success' => true],
    ]);

    $client = MetaGraph::asPage($this->page());

    $created = $client->node('111')->edge('feed')
        ->fields(FieldSet::make('id', 'created_time', 'message'))
        ->post(['message' => 'Hallo']);

    $client->node('111_222')->delete();

    expect($created->json('created_time'))->toBe('2026-10-31T10:00:00+0000');

    $fake->assertPosted('*/feed', fn (array $params) => $params['message'] === 'Hallo' && $params['fields'] === 'id,created_time,message');
    $fake->assertDeleted('v26.0/111_222');
});

it('allows overriding the version per request and per client', function () {
    $fake = MetaGraph::fake(['*' => ['id' => 'me']]);

    MetaGraph::asUser('user-token')->me()->version('v25.0')->get();
    MetaGraph::asUser('user-token')->usingVersion('v24.0')->me()->get();
    config()->set('meta-graph.version', 'v23.0');
    MetaGraph::asUser('user-token')->me()->get();

    $fake->assertFetched('v25.0/me')->assertFetched('v24.0/me')->assertFetched('v23.0/me');
});

it('fetches several nodes with ids and passes since/until as timestamps', function () {
    $fake = MetaGraph::fake(['*' => ['1' => ['id' => '1'], '2' => ['id' => '2']]]);
    $since = now()->subDay();

    $response = MetaGraph::asUser('t')->nodes(['1', '2'])->fields('id')->get();
    MetaGraph::asUser('t')->node('111')->edge('feed')->since($since)->until(1700000000)->limit(5)->get();

    expect($response->json('2.id'))->toBe('2');
    $fake->assertFetched('v26.0', fn (array $p) => $p['ids'] === '1,2');
    $fake->assertFetched('v26.0/111/feed', fn (array $p) => $p['since'] === (string) $since->getTimestamp() && $p['until'] === '1700000000' && $p['limit'] === '5');
});

it('can disable appsecret_proof', function () {
    config()->set('meta-graph.appsecret_proof', false);
    $fake = MetaGraph::fake(['*' => ['id' => 'me']]);

    MetaGraph::asUser('t')->me()->get();

    $fake->assertFetched('v26.0/me', fn (array $p) => ! isset($p['appsecret_proof']) && $p['access_token'] === 't');
});

it('uses the app token for asApp and different apps from config', function () {
    $fake = MetaGraph::fake(['*' => ['id' => 'x']]);

    MetaGraph::asApp()->get('1234567890/subscriptions');
    MetaGraph::app('kunde-b')->asApp()->get('999/subscriptions');

    $fake->assertFetched('*/1234567890/subscriptions', fn (array $p) => $p['access_token'] === '1234567890|test-app-secret');
    $fake->assertFetched('*/999/subscriptions', fn (array $p) => $p['access_token'] === '999|secret-b'
        && $p['appsecret_proof'] === hash_hmac('sha256', '999|secret-b', 'secret-b'));
});

it('supports system user tokens and resolves the client from the container', function () {
    $fake = MetaGraph::fake(['*' => ['id' => 'x']]);

    MetaGraph::asSystemUser('system-token')->me()->get();
    app(GraphClient::class)->withToken(AccessToken::user('u'))->me()->get();

    expect(MetaGraph::asSystemUser('system-token')->token()?->type->value)->toBe('system_user')
        ->and(app(GraphManager::class)->appName())->toBe('main');
    $fake->assertSentCount(2);
});

it('translates graph errors into typed exceptions and fires auth events', function () {
    Event::fake();
    MetaGraph::fake([
        'v26.0/expired' => GraphFake::error(190, 'Error validating access token', 463),
        'v26.0/checkpoint' => GraphFake::error(190, 'Checkpoint', 459),
        'v26.0/duplicate' => GraphFake::error(506, 'Duplicate status message', type: 'GraphMethodException'),
    ]);

    $client = MetaGraph::asUser('user-token');

    expect(fn () => $client->get('expired'))->toThrow(AuthenticationException::class, 'Error validating access token')
        ->and(fn () => $client->get('checkpoint'))->toThrow(UserActionRequiredException::class)
        ->and(fn () => $client->get('duplicate'))->toThrow(DuplicatePostException::class);

    Event::assertDispatched(TokenInvalidated::class, fn (TokenInvalidated $e) => $e->token?->value === 'user-token' && $e->app === 'main');
    Event::assertDispatched(UserActionRequired::class, fn (UserActionRequired $e) => str_contains($e->hint, 'Checkpoint'));
    Event::assertDispatchedTimes(TokenInvalidated::class, 1);
});

it('returns a helpful error for unstubbed requests', function () {
    MetaGraph::fake();

    expect(fn () => MetaGraph::asUser('t')->get('unknown'))->toThrow(GraphException::class, 'GraphFake: no stub registered for [GET v26.0/unknown]');
});

it('sends raw bodies with the token in the authorization header', function () {
    Http::fake(['*' => Http::response(['h' => 'handle'])]);

    $client = MetaGraph::asUser('user-token');
    $client->send(
        $client->request('POST', 'upload:abc')
            ->tokenInHeader()
            ->withHeaders(['file_offset' => '0', 'Content-Type' => 'application/octet-stream'])
            ->withBody('binary'),
    );

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'OAuth user-token')
        && $request->hasHeader('file_offset', '0')
        && $request->body() === 'binary'
        && str_contains($request->url(), 'appsecret_proof=')
        && ! str_contains($request->url(), 'access_token='));
});

it('builds immutable requests', function () {
    $request = GraphRequest::get('111', ['a' => 1]);
    $changed = $request->edge('feed')->withParam('b', 2)->version('v25.0')->host(GraphRequest::VIDEO_HOST);

    expect($request->url())->toBe('https://graph.facebook.com/v26.0/111')
        ->and($request->params)->toBe(['a' => 1])
        ->and($changed->url())->toBe('https://graph-video.facebook.com/v25.0/111/feed')
        ->and($changed->withoutParam('a')->params)->toBe(['b' => 2])
        ->and(GraphRequest::post('x')->withUrl('https://rupload.facebook.com/video-upload/v26.0/9?x=1')->url())
        ->toBe('https://rupload.facebook.com/video-upload/v26.0/9')
        ->and(GraphRequest::delete('/x/')->path)->toBe('x')
        ->and(GraphRequest::get('x')->fields([])->params)->toBe([]);
});
