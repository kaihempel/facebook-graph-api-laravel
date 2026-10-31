<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Kuweh\MetaGraph\Events\RateLimitApproaching;
use Kuweh\MetaGraph\Events\RateLimitHit;
use Kuweh\MetaGraph\Exceptions\GraphException;
use Kuweh\MetaGraph\Exceptions\RateLimitException;
use Kuweh\MetaGraph\Exceptions\TransientException;
use Kuweh\MetaGraph\Facades\MetaGraph;
use Kuweh\MetaGraph\Http\RetryPolicy;
use Kuweh\MetaGraph\Testing\GraphFake;
use Psr\Log\LoggerInterface;

it('retries transient errors and 5xx up to the configured times', function () {
    $fake = MetaGraph::fake([
        'v26.0/me' => GraphFake::sequence()
            ->pushError(1, 'Unknown error')
            ->push(GraphFake::response(['error' => ['message' => 'oops']], 500))
            ->push(['id' => 'me']),
    ]);

    expect(MetaGraph::asUser('t')->me()->get()->json('id'))->toBe('me');

    $fake->assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('gives up after the configured number of retries', function () {
    config()->set('meta-graph.retry.times', 2);
    $fake = MetaGraph::fake(['v26.0/me' => GraphFake::error(2, 'Down', status: 503)]);

    expect(fn () => MetaGraph::asUser('t')->me()->get())->toThrow(TransientException::class, 'Down');
    $fake->assertSentCount(3);
});

it('never retries rate limit and content errors in the same process', function () {
    Event::fake([RateLimitHit::class]);
    $fake = MetaGraph::fake([
        'v26.0/limited' => GraphFake::error(32, 'Page request limit reached'),
        'v26.0/invalid' => GraphFake::error(100, 'Invalid parameter'),
    ]);

    expect(fn () => MetaGraph::asUser('t')->get('limited'))->toThrow(RateLimitException::class)
        ->and(fn () => MetaGraph::asUser('t')->get('invalid'))->toThrow(GraphException::class);

    $fake->assertSentCount(2);
    Sleep::assertNeverSlept();
    Event::assertDispatched(RateLimitHit::class, fn (RateLimitHit $e) => $e->exception->getCode() === 32);
    expect(MetaGraph::rateLimits()->blockedFor(MetaGraph::appConfig()))->toBeGreaterThan(0);
});

it('does not retry non-retryable requests', function () {
    $fake = MetaGraph::fake(['*' => GraphFake::error(2, 'Down', status: 500)]);
    $client = MetaGraph::asUser('t');

    expect(fn () => $client->send($client->request('POST', 'upload')->retryable(false)))->toThrow(TransientException::class);
    $fake->assertSentCount(1);
});

it('retries connection errors and wraps them as transient exceptions', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('cURL error 28: timeout https://graph.facebook.com/v26.0/me?access_token=secret-token-value');
    });

    try {
        MetaGraph::asUser('secret-token-value')->me()->get();
        $this->fail('Expected exception');
    } catch (TransientException $e) {
        expect($e->type)->toBe('ConnectionException')
            ->and($e->getMessage())->not->toContain('secret-token-value')
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }

    expect($calls)->toBe(4);
});

it('computes exponential backoff with jitter', function () {
    $policy = new RetryPolicy(times: 3, baseMs: 1000, jitter: false);
    $jitter = new RetryPolicy(times: 3, baseMs: 1000);

    expect($policy->delayMs(1))->toBe(1000)
        ->and($policy->delayMs(2))->toBe(4000)
        ->and($policy->delayMs(3))->toBe(16000)
        ->and($jitter->delayMs(2))->toBeGreaterThanOrEqual(4000)->toBeLessThanOrEqual(4400)
        ->and($policy->shouldRetry(3))->toBeTrue()
        ->and($policy->shouldRetry(4))->toBeFalse()
        ->and($policy->shouldRetry(1, new RateLimitException('x')))->toBeFalse()
        ->and(RetryPolicy::none()->shouldRetry(1))->toBeFalse()
        ->and(RetryPolicy::fromConfig(['times' => 5, 'base_ms' => 10])->times)->toBe(5);
});

it('tracks usage headers and fires RateLimitApproaching once when crossing the threshold', function () {
    Event::fake([RateLimitApproaching::class]);
    MetaGraph::fake([
        'v26.0/me' => GraphFake::sequence()
            ->push(GraphFake::response(['id' => '1'])->withUsage(['call_count' => 10]))
            ->push(GraphFake::response(['id' => '1'])->withUsage(['call_count' => 85]))
            ->push(GraphFake::response(['id' => '1'])->withUsage(['call_count' => 90]))
            ->push(GraphFake::response(['id' => '1'])->withUsage(businessUseCases: [
                '42' => [['type' => 'pages', 'call_count' => 100, 'total_cputime' => 10, 'total_time' => 10, 'estimated_time_to_regain_access' => 5]],
            ])),
    ]);

    $client = MetaGraph::asUser('t');
    $first = $client->me()->get();
    $client->me()->get();
    $client->me()->get();
    $client->me()->get();

    $tracker = MetaGraph::rateLimits();
    $usage = $tracker->current(MetaGraph::appConfig());

    expect($first->usage()->app?->callCount)->toBe(10)
        ->and($usage->app?->callCount)->toBe(90)
        ->and($usage->businessUseCases[0]->businessId)->toBe('42')
        ->and($tracker->blockedFor(MetaGraph::appConfig()))->toBeGreaterThan(290)
        ->and($tracker->warnAt())->toBe(80);

    Event::assertDispatchedTimes(RateLimitApproaching::class, 1);
    Event::assertDispatched(RateLimitApproaching::class, fn (RateLimitApproaching $e) => $e->usage->max() === 85 && $e->threshold === 80);

    $tracker->reset(MetaGraph::appConfig());
    expect($tracker->current(MetaGraph::appConfig())->isEmpty())->toBeTrue()
        ->and($tracker->blockedFor(MetaGraph::appConfig()))->toBe(0);
});

it('treats a fully used app as blocked', function () {
    MetaGraph::fake(['*' => GraphFake::response(['id' => 1])->withUsage(['call_count' => 100])]);

    MetaGraph::asUser('t')->me()->get();

    expect(MetaGraph::rateLimits()->blockedFor(MetaGraph::appConfig()))->toBe(RateLimitException::DEFAULT_RETRY_AFTER);
});

it('logs requests with masked tokens when enabled', function () {
    config()->set('meta-graph.logging.log_requests', true);
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->andReturn($logger);
    MetaGraph::fake(['*' => GraphFake::response(['id' => 1])->withHeaders(['x-fb-trace-id' => 'trace'])]);

    MetaGraph::asUser('secret-user-token')->me()->get();

    $logger->shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'Graph API request'
        && $context['status'] === 200
        && $context['fbtrace_id'] === 'trace'
        && ! str_contains(json_encode($context), 'secret-user-token'));
});
