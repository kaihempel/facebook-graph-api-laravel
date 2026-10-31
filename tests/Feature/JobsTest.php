<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Event;
use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Events\MediaProcessingFinished;
use Kuweh\MetaGraph\Events\PageConnected;
use Kuweh\MetaGraph\Events\PostScheduled;
use Kuweh\MetaGraph\Exceptions\MediaProcessingException;
use Kuweh\MetaGraph\Exceptions\RateLimitException;
use Kuweh\MetaGraph\Facades\MetaGraph;
use Kuweh\MetaGraph\Jobs\Middleware\RespectMetaRateLimits;
use Kuweh\MetaGraph\Jobs\PublishPost;
use Kuweh\MetaGraph\Jobs\PublishReel;
use Kuweh\MetaGraph\Jobs\RefreshPageTokens;
use Kuweh\MetaGraph\Jobs\UploadVideo;
use Kuweh\MetaGraph\Media\MediaSource;
use Kuweh\MetaGraph\Resources\Posts\PostDraft;
use Kuweh\MetaGraph\Testing\GraphFake;

/**
 * Bindet einen gefakten Queue-Job, um release()-Aufrufe zu prüfen.
 */
function fakeQueueJob(object $job): Job
{
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->byDefault();
    $queueJob->shouldReceive('isReleased')->andReturn(false)->byDefault();
    $queueJob->shouldReceive('fail')->byDefault();
    $job->setJob($queueJob);

    return $queueJob;
}

it('publishes and schedules posts from the queue with a stored page token', function () {
    CarbonImmutable::setTestNow('2026-10-31 12:00:00');
    Event::fake([PostScheduled::class]);
    $fake = MetaGraph::fake(['v26.0/111/feed' => ['id' => '111_1']]);
    MetaGraph::connectPage($this->page());

    PublishPost::dispatch('111', PostDraft::make('Aus der Queue'));
    PublishPost::dispatch('111', PostDraft::make('Geplant'), now()->addDay());
    PublishPost::dispatch('111', PostDraft::make('Mit Token'), null, AccessToken::page('explicit'));

    $fake->assertPosted('v26.0/111/feed', fn (array $p) => $p['message'] === 'Aus der Queue' && $p['access_token'] === 'page-token-111');
    $fake->assertPosted('v26.0/111/feed', fn (array $p) => $p['message'] === 'Mit Token' && $p['access_token'] === 'explicit');
    Event::assertDispatched(PostScheduled::class);
    CarbonImmutable::setTestNow();
});

it('is serializable and encrypted on the queue', function () {
    $job = new PublishPost('111', PostDraft::make('x')->photo(MediaSource::url('https://cdn.example.com/a.jpg')), now(), AccessToken::page('secret'));

    $restored = unserialize(serialize($job));

    expect($restored->draft->photos[0]->remoteUrl())->toBe('https://cdn.example.com/a.jpg')
        ->and($restored->pageToken?->value)->toBe('secret')
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->middleware()[0])->toBeInstanceOf(RespectMetaRateLimits::class);
});

it('releases jobs while the app is rate limited', function () {
    MetaGraph::fake();
    MetaGraph::rateLimits()->block(MetaGraph::appConfig(), 120);
    $job = new PublishPost('111', PostDraft::make('x'), pageToken: AccessToken::page('t'));
    $queueJob = fakeQueueJob($job);

    (new RespectMetaRateLimits)->handle($job, fn () => throw new RuntimeException('must not run'));

    $queueJob->shouldHaveReceived('release')->with(120);
});

it('releases jobs on rate limit errors with the estimated wait time', function () {
    $job = new PublishPost('111', PostDraft::make('x'), pageToken: AccessToken::page('t'), app: 'kunde-b');
    $queueJob = fakeQueueJob($job);

    (new RespectMetaRateLimits('kunde-b'))->handle($job, fn () => throw new RateLimitException('limit'));

    $queueJob->shouldHaveReceived('release')->with(RateLimitException::DEFAULT_RETRY_AFTER);
});

it('passes through when not limited and rethrows for jobs without release', function () {
    $result = (new RespectMetaRateLimits)->handle(new stdClass, fn () => 'ran');

    expect($result)->toBe('ran')
        ->and(fn () => (new RespectMetaRateLimits)->handle(new stdClass, fn () => throw new RateLimitException('x')))
        ->toThrow(RateLimitException::class);
});

it('uploads and publishes videos exactly once', function () {
    $fake = MetaGraph::fake([
        'v26.0/1234567890/uploads' => ['id' => 'upload:S'],
        'v26.0/upload:S' => ['h' => 'H'],
        'v26.0/111/videos' => ['id' => 'vid'],
    ]);
    $source = MediaSource::contents('video', 'v.mp4', 'video/mp4');
    $job = new UploadVideo('111', $source, AccessToken::user('user-token'), 'Titel', pageToken: AccessToken::page('pt'));

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    $fake->assertSentCount(3);
    $fake->assertPosted('v26.0/111/videos', fn (array $p) => $p['fbuploader_video_file_chunk'] === 'H' && $p['access_token'] === 'pt');
    expect($job->uniqueId())->toStartWith('111:video:')
        ->and((new UploadVideo('1', $source, AccessToken::user('u'), resumeKey: 'k'))->uniqueId())->toBe('k');
});

it('runs the reel flow with status polling via release', function () {
    Event::fake([MediaProcessingFinished::class]);
    $fake = MetaGraph::fake([
        'v26.0/111/video_reels' => fn (array $p) => $p['upload_phase'] === 'start'
            ? GraphFake::response(['video_id' => '999', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/999'])
            : GraphFake::response(['success' => true]),
        'rupload.facebook.com/*' => ['success' => true],
        'v26.0/999' => GraphFake::sequence()
            ->push(['status' => ['video_status' => 'uploading', 'uploading_phase' => ['status' => 'in_progress', 'bytes_transfered' => 0]]])
            ->push(['status' => ['video_status' => 'upload_complete', 'uploading_phase' => ['status' => 'complete']]])
            ->push(['status' => ['video_status' => 'processing', 'uploading_phase' => ['status' => 'complete'], 'processing_phase' => ['status' => 'in_progress']]])
            ->push(['status' => ['video_status' => 'ready', 'uploading_phase' => ['status' => 'complete'], 'publishing_phase' => ['status' => 'complete']]]),
    ]);

    $job = new PublishReel('111', MediaSource::contents('reel', 'r.mp4', 'video/mp4'), 'Beschreibung', pageToken: AccessToken::page('pt'), pollSeconds: 15);
    $queueJob = fakeQueueJob($job);

    app()->call([$job, 'handle']); // start + upload, Upload noch nicht fertig → release
    app()->call([$job, 'handle']); // upload_complete → finish, processing → release
    app()->call([$job, 'handle']); // ready

    $queueJob->shouldHaveReceived('release')->with(15)->twice();
    $fake->assertPosted('v26.0/111/video_reels', fn (array $p) => $p['upload_phase'] === 'finish' && $p['description'] === 'Beschreibung');
    $fake->assertSent('v26.0/111/video_reels', fn (array $p) => $p['upload_phase'] === 'start');
    expect(collect($fake->recorded())->filter(fn ($r) => ($r->params['upload_phase'] ?? null) === 'start'))->toHaveCount(1);
    Event::assertDispatched(MediaProcessingFinished::class, fn ($e) => $e->videoId === '999' && $e->status->isReady());
});

it('fails the reel job with the processing error', function () {
    Event::fake([MediaProcessingFinished::class]);
    MetaGraph::fake([
        'v26.0/111/video_reels' => ['video_id' => '999'],
        'rupload.facebook.com/*' => ['success' => true],
        'v26.0/999' => ['status' => ['video_status' => 'error', 'processing_phase' => ['status' => 'error', 'errors' => [['code' => 1, 'message' => 'Resolution too low']]]]],
    ]);

    $job = new PublishReel('111', MediaSource::contents('reel', 'r.mp4'), pageToken: AccessToken::page('pt'));
    fakeQueueJob($job);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(MediaProcessingException::class, 'Resolution too low');
    Event::assertDispatched(MediaProcessingFinished::class, fn ($e) => $e->status->isFailed());
});

it('refreshes page tokens from the stored user token', function () {
    Event::fake([PageConnected::class]);
    MetaGraph::fake(['v26.0/me/accounts' => ['data' => [
        ['id' => '1', 'name' => 'Eins', 'tasks' => [], 'access_token' => 'pt1'],
        ['id' => '2', 'name' => 'Zwei', 'tasks' => [], 'access_token' => 'pt2'],
    ]]]);
    MetaGraph::tokenRepository()->store('user', '42', AccessToken::user('long-lived'));

    $job = new RefreshPageTokens('42');
    app()->call([$job, 'handle']);

    expect(MetaGraph::tokenRepository()->find('page', '2')?->value)->toBe('pt2')
        ->and($job->uniqueId())->toBe('default:42');
    Event::assertDispatchedTimes(PageConnected::class, 2);
});

it('fails refreshing without stored user token', function () {
    $job = new RefreshPageTokens('404', 'kunde-b');
    $queueJob = fakeQueueJob($job);

    app()->call([$job, 'handle']);

    $queueJob->shouldHaveReceived('fail');
});
