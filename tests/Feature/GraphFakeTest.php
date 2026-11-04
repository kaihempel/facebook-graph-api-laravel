<?php

declare(strict_types=1);

use KaiHempel\MetaGraph\Exceptions\RateLimitException;
use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\Graph\Batch\BatchRequest;
use KaiHempel\MetaGraph\Media\MediaSource;
use KaiHempel\MetaGraph\Testing\GraphFake;
use PHPUnit\Framework\AssertionFailedError;

it('works as documented in the specification', function () {
    $fake = MetaGraph::fake([
        'v26.0/*/feed' => GraphFake::response(['id' => '123_456']),
        'v26.0/*/video_reels' => GraphFake::sequence()
            ->push(['video_id' => '999', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/999'])
            ->push(['success' => true]),
        'v26.0/*/photos' => GraphFake::error(code: 32, message: 'Page request limit reached'),
        'v26.0' => GraphFake::batch([['id' => '1'], ['id' => '2'], ['id' => '3']]),
    ]);

    $client = MetaGraph::asPage($this->page());
    $client->posts()->create('Hallo');
    $client->reels()->start();
    $client->batch(function (BatchRequest $batch) {
        $batch->get('1');
        $batch->get('2');
        $batch->get('3');
    });

    expect(fn () => $client->photos()->publish(MediaSource::url('https://x.test/a.jpg')))
        ->toThrow(RateLimitException::class, 'Page request limit reached');

    $fake->assertPosted('*/feed', fn ($params) => $params['message'] === 'Hallo');
    $fake->assertBatchSent(count: 3);
});

it('asserts that nothing was sent', function () {
    $fake = MetaGraph::fake();

    $fake->assertNothingSent()->assertSentCount(0);

    MetaGraph::fake(['*' => ['id' => 1]]);
    MetaGraph::asUser('t')->me()->get();

    expect(fn () => $fake->assertNothingSent())->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertPosted('v26.0/me'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertBatchSent())->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNotSent('v26.0/me'))->toThrow(AssertionFailedError::class);

    $fake->assertNotSent('v26.0/other')->assertFetched('v26.0/me')->assertSent('v26.0/me', method: 'GET');
});

it('supports later stubs with precedence and closures returning arrays', function () {
    $fake = MetaGraph::fake(['v26.0/*' => ['id' => 'generic']]);
    $fake->stub(['v26.0/special' => fn (array $params) => ['id' => 'special', 'echo' => $params['x']]]);

    expect(MetaGraph::asUser('t')->get('special', ['x' => 'y'])->json('echo'))->toBe('y')
        ->and(MetaGraph::asUser('t')->get('other')->json('id'))->toBe('generic');
});

it('falls back when a sequence is empty', function () {
    MetaGraph::fake(['*' => GraphFake::sequence()->push(['n' => 1])->whenEmpty(['n' => 'empty'])]);

    $client = MetaGraph::asUser('t');

    expect($client->get('a')->json('n'))->toBe(1)
        ->and($client->get('a')->json('n'))->toBe('empty');
});

it('throws when a sequence without fallback is exhausted', function () {
    $sequence = GraphFake::sequence()->push(['n' => 1]);
    $sequence->next();

    expect($sequence->isEmpty())->toBeTrue()
        ->and(fn () => $sequence->next())->toThrow(OutOfBoundsException::class);
});

it('fakes usage headers and generic stubs', function () {
    MetaGraph::fake(['*' => GraphFake::response(['ok' => true])->withUsage(['call_count' => 50], ['1' => [['type' => 'pages', 'call_count' => 70]]])]);

    $usage = MetaGraph::asUser('t')->me()->get()->usage();

    expect($usage->app?->callCount)->toBe(50)
        ->and($usage->businessUseCases[0]->callCount)->toBe(70);
});
