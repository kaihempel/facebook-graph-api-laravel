<?php

declare(strict_types=1);

use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Exceptions\BatchTooLargeException;
use Kuweh\MetaGraph\Exceptions\DuplicatePostException;
use Kuweh\MetaGraph\Facades\MetaGraph;
use Kuweh\MetaGraph\Graph\Batch\BatchRequest;
use Kuweh\MetaGraph\Media\MediaSource;
use Kuweh\MetaGraph\Testing\GraphFake;
use Kuweh\MetaGraph\Testing\RecordedRequest;

it('sends batch requests with names, dependencies, per-operation tokens and files', function () {
    $fake = MetaGraph::fake([
        'v26.0' => GraphFake::batch([
            ['id' => '111_1'],
            ['111_1' => ['id' => '111_1']],
            ['id' => 'photo', 'post_id' => '111_2'],
            GraphFake::error(506, 'Duplicate'),
        ]),
    ]);

    $otherToken = AccessToken::page('other-page-token');
    $upload = MediaSource::contents('jpegdata', 'foto.jpg', 'image/jpeg');

    $results = MetaGraph::asUser('user-token')->batch(function (BatchRequest $batch) use ($otherToken, $upload) {
        $batch->post('111/feed', ['message' => 'Test'])->name('create');
        $batch->get('?ids={result=create:$.id}')->dependsOn('create');
        $batch->post('111/photos', ['caption' => 'Foto'])->attach('file1', $upload);
        $batch->get('222')->withToken($otherToken)->fields(['id', 'name']);
    });

    expect($results)->toHaveCount(4)
        ->and($results->get('create')?->json('id'))->toBe('111_1')
        ->and($results->get('create')?->status())->toBe(200)
        ->and($results->get(1)?->successful())->toBeTrue()
        ->and($results->get(3)?->successful())->toBeFalse()
        ->and($results->get(3)?->exception())->toBeInstanceOf(DuplicatePostException::class)
        ->and($results->failed())->toHaveCount(1)
        ->and($results->successful())->toHaveCount(3)
        ->and($results->allSuccessful())->toBeFalse()
        ->and(array_keys($results->all()))->toBe(['create', 1, 2, 3]);

    $fake->assertBatchSent(count: 4, callback: function (array $operations) {
        return $operations[0] === ['method' => 'POST', 'relative_url' => '111/feed', 'body' => 'message=Test', 'name' => 'create']
            && $operations[1]['depends_on'] === 'create'
            && $operations[1]['relative_url'] === '?ids={result=create:$.id}'
            && $operations[2]['attached_files'] === 'file1'
            && str_contains($operations[3]['relative_url'], 'access_token=other-page-token')
            && str_contains($operations[3]['relative_url'], 'appsecret_proof=');
    });

    $batch = $fake->recorded(fn (RecordedRequest $r) => $r->isBatch())[0];

    expect($batch->params['access_token'])->toBe('user-token')
        ->and($batch->params['include_headers'])->toBe('false')
        ->and($batch->files)->toBe(['file1' => 'foto.jpg']);
});

it('uses the app token as mandatory top-level token', function () {
    $fake = MetaGraph::fake(['v26.0' => GraphFake::batch([['id' => '1']])]);

    MetaGraph::client()->batch(fn (BatchRequest $b) => $b->get('1')->withToken(AccessToken::page('p')));

    $fake->assertSent('v26.0', fn (array $p) => $p['access_token'] === '1234567890|test-app-secret');
});

it('splits more than 50 operations into chunks', function () {
    $fake = MetaGraph::fake([
        'v26.0' => fn (array $params) => GraphFake::batch(array_map(
            fn (array $op) => ['id' => $op['relative_url']],
            json_decode($params['batch'], true),
        )),
    ]);

    $results = MetaGraph::asUser('t')->batch(function (BatchRequest $batch) {
        foreach (range(1, 120) as $i) {
            $batch->get((string) $i);
        }
    });

    expect($results)->toHaveCount(120)
        ->and($results->get(119)?->json('id'))->toBe('120');
    $fake->assertSentCount(3)->assertBatchSent(count: 120);
});

it('throws in strict mode for more than 50 operations', function () {
    MetaGraph::fake();
    $batch = new BatchRequest;
    foreach (range(1, 51) as $i) {
        $batch->get((string) $i);
    }

    MetaGraph::asUser('t')->batchStrict($batch);
})->throws(BatchTooLargeException::class);

it('marks timed out operations and retries failed ones', function () {
    $fake = MetaGraph::fake([
        'v26.0' => GraphFake::sequence()
            ->push(GraphFake::batch([['id' => '1'], null, null]))
            ->push(GraphFake::batch([['id' => '2'], ['id' => '3']])),
    ]);

    $results = MetaGraph::asUser('t')->batch(function (BatchRequest $batch) {
        $batch->get('1');
        $batch->get('2')->name('second');
        $batch->delete('3')->omitResponseOnSuccess(false);
    });

    expect($results->timedOut())->toHaveCount(2)
        ->and($results->get('second')?->isTimedOut())->toBeTrue()
        ->and($results->get('second')?->exception()?->isTransient())->toBeTrue()
        ->and($results->get('second')?->status())->toBeNull();

    $retried = $results->failed()->retry();

    expect($retried)->toHaveCount(2)
        ->and($retried->get('second')?->json('id'))->toBe('2')
        ->and($retried->allSuccessful())->toBeTrue()
        ->and($results->successful()->failed()->retry())->toHaveCount(0);

    $fake->assertBatchSent(count: 5, callback: fn (array $ops) => count($ops) === 2
        && $ops[1] === ['method' => 'DELETE', 'relative_url' => '3', 'omit_response_on_success' => false]);
});
