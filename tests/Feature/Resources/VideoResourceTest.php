<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Contracts\UploadSessionStore;
use KaiHempel\MetaGraph\Data\MediaState;
use KaiHempel\MetaGraph\Data\UploadSession;
use KaiHempel\MetaGraph\Events\MediaUploadProgressed;
use KaiHempel\MetaGraph\Exceptions\MediaProcessingException;
use KaiHempel\MetaGraph\Exceptions\TransientException;
use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\Media\MediaSource;
use KaiHempel\MetaGraph\Testing\GraphFake;

function videoFile(string $contents = 'video-bytes-0123456789'): string
{
    $path = sys_get_temp_dir().'/meta-graph-test-'.uniqid().'.mp4';
    file_put_contents($path, $contents);

    return $path;
}

it('uploads via the resumable upload api with the user token and publishes with the page token', function () {
    Event::fake([MediaUploadProgressed::class]);
    $fake = MetaGraph::fake([
        'v26.0/1234567890/uploads' => ['id' => 'upload:SESSION'],
        'v26.0/upload:SESSION' => ['h' => 'HANDLE'],
        'v26.0/111/videos' => ['id' => 'vid1'],
    ]);
    $source = MediaSource::file(videoFile(), 'clip.mp4', 'video/mp4');

    $video = MetaGraph::asPage($this->page())->videos()->publish(
        $source,
        title: 'Titel',
        description: 'Beschreibung',
        uploadToken: AccessToken::user('user-token'),
    );

    expect($video->id)->toBe('vid1')
        ->and($video->title)->toBe('Titel')
        ->and($video->description)->toBe('Beschreibung');

    $fake->assertPosted('v26.0/1234567890/uploads', fn (array $p) => $p['file_name'] === 'clip.mp4'
        && $p['file_length'] === '22'
        && $p['file_type'] === 'video/mp4'
        && $p['access_token'] === 'user-token');
    $fake->assertPosted('v26.0/upload:SESSION', fn (array $p, $r) => $r->header('Authorization') === 'OAuth user-token'
        && $r->header('file_offset') === '0'
        && $r->request->body() === 'video-bytes-0123456789');
    $fake->assertPosted('v26.0/111/videos', fn (array $p) => $p['fbuploader_video_file_chunk'] === 'HANDLE'
        && $p['title'] === 'Titel'
        && $p['access_token'] === 'page-token-111');
    Event::assertDispatched(MediaUploadProgressed::class, fn ($e) => $e->bytesTransferred === 22 && $e->totalBytes === 22);
});

it('resumes an interrupted upload at the server side offset', function () {
    $fake = MetaGraph::fake([
        'v26.0/upload:SESSION' => fn (array $p, $r) => $r->method === 'GET'
            ? GraphFake::response(['id' => 'upload:SESSION', 'file_offset' => 12])
            : GraphFake::response(['h' => 'HANDLE']),
    ]);
    $source = MediaSource::file(videoFile(), 'clip.mp4', 'video/mp4');
    app(UploadSessionStore::class)->store('job-key', new UploadSession('upload:SESSION', 'clip.mp4', 22, 'video/mp4'));

    $handle = MetaGraph::asUser('user-token')->videos()->upload($source, resumeKey: 'job-key');

    expect($handle)->toBe('HANDLE')
        ->and(app(UploadSessionStore::class)->find('job-key')?->handle)->toBe('HANDLE');

    $fake->assertNotSent('*/uploads');
    $fake->assertPosted('v26.0/upload:SESSION', fn (array $p, $r) => $r->header('file_offset') === '12'
        && $r->request->body() === '0123456789');

    // Bereits abgeschlossene Session: kein weiterer Request
    MetaGraph::asUser('user-token')->videos()->upload($source, resumeKey: 'job-key');
    $fake->assertSentCount(2);
});

it('continues a failed transfer step instead of restarting the flow', function () {
    $fake = MetaGraph::fake([
        'v26.0/1234567890/uploads' => ['id' => 'upload:S2'],
        'v26.0/upload:S2' => GraphFake::sequence()
            ->pushError(2, 'Temporary', status: 500)
            ->push(['id' => 'upload:S2', 'file_offset' => 5])
            ->push(['h' => 'H2']),
    ]);

    $handle = MetaGraph::asUser('user-token')->videos()->upload(MediaSource::file(videoFile(), 'c.mp4', 'video/mp4'));

    expect($handle)->toBe('H2');
    $fake->assertSentCount(4);
    $fake->assertPosted('v26.0/upload:S2', fn (array $p, $r) => $r->header('file_offset') === '5');
});

it('gives up a transfer after the configured retries', function () {
    config()->set('meta-graph.retry.times', 1);
    MetaGraph::fake([
        'v26.0/1234567890/uploads' => ['id' => 'upload:S3'],
        'v26.0/upload:S3' => fn (array $p, $r) => $r->method === 'GET'
            ? GraphFake::response(['file_offset' => 0])
            : GraphFake::error(2, 'Down', status: 500),
    ]);

    MetaGraph::asUser('user-token')->videos()->upload(MediaSource::file(videoFile(), 'd.mp4', 'video/mp4'));
})->throws(TransientException::class);

it('publishes remote videos via file_url and handles directly', function () {
    $fake = MetaGraph::fake(['v26.0/111/videos' => ['id' => 'vid2']]);
    $videos = MetaGraph::asPage($this->page())->videos();

    $videos->publish(MediaSource::url('https://cdn.example.com/v.mp4'), 'T', scheduledAt: now()->addDay());
    $videos->publish('EXISTING-HANDLE', 'T2');

    $fake->assertPosted('v26.0/111/videos', fn (array $p) => ($p['file_url'] ?? null) === 'https://cdn.example.com/v.mp4' && $p['published'] === 'false' && isset($p['scheduled_publish_time']));
    $fake->assertPosted('v26.0/111/videos', fn (array $p) => ($p['fbuploader_video_file_chunk'] ?? null) === 'EXISTING-HANDLE');
});

it('never retries the publish step blindly', function () {
    $fake = MetaGraph::fake(['v26.0/111/videos' => GraphFake::error(2, 'Temporary', status: 500)]);

    expect(fn () => MetaGraph::asPage($this->page())->videos()->publish('H', 'T'))->toThrow(TransientException::class);
    $fake->assertSentCount(1);
});

it('rejects uploading remote sources', function () {
    MetaGraph::asUser('t')->videos()->upload(MediaSource::url('https://cdn.example.com/v.mp4'));
})->throws(InvalidArgumentException::class);

it('supports the legacy chunked upload driver on graph-video', function () {
    config()->set('meta-graph.uploads.video_driver', 'chunked');
    $fake = MetaGraph::fake([
        'graph-video.facebook.com/v26.0/111/videos' => fn (array $p) => match ($p['upload_phase']) {
            'start' => GraphFake::response(['upload_session_id' => 'sess', 'video_id' => 'vid3', 'start_offset' => '0', 'end_offset' => '10']),
            'transfer' => $p['start_offset'] === '0'
                ? GraphFake::response(['start_offset' => '10', 'end_offset' => '22'])
                : GraphFake::response(['start_offset' => '22', 'end_offset' => '22']),
            'finish' => GraphFake::response(['success' => true]),
        },
    ]);

    $video = MetaGraph::asPage($this->page())->videos()->publish(
        MediaSource::file(videoFile(), 'clip.mp4', 'video/mp4'),
        title: 'Alt',
    );

    expect($video->id)->toBe('vid3')->and($video->title)->toBe('Alt');
    $fake->assertSentCount(4);
    $fake->assertPosted('graph-video.facebook.com/*', fn (array $p, $r) => $p['upload_phase'] === 'transfer' && $p['start_offset'] === '10' && $r->files === ['video_file_chunk' => 'clip.mp4']);
    $fake->assertPosted('graph-video.facebook.com/*', fn (array $p) => $p['upload_phase'] === 'finish' && $p['title'] === 'Alt' && $p['upload_session_id'] === 'sess');
});

it('maps the video status and throws processing errors', function () {
    MetaGraph::fake([
        'v26.0/vid1' => ['id' => 'vid1', 'status' => ['video_status' => 'ready', 'uploading_phase' => ['status' => 'complete']]],
        'v26.0/vid2' => ['id' => 'vid2', 'status' => [
            'video_status' => 'error',
            'processing_phase' => ['status' => 'error', 'errors' => [['code' => 1363008, 'message' => 'Video resolution is too low (min 540p).']]],
        ]],
    ]);

    $videos = MetaGraph::asPage($this->page())->videos();
    $ready = $videos->status('vid1');
    $failed = $videos->status('vid2');

    expect($ready->state)->toBe(MediaState::Ready)
        ->and($ready->isReady())->toBeTrue()
        ->and($ready->throwIfFailed())->toBe($ready)
        ->and($failed->isFailed())->toBeTrue()
        ->and($failed->errorMessage())->toContain('540p')
        ->and(fn () => $failed->throwIfFailed())->toThrow(MediaProcessingException::class, 'Video resolution is too low (min 540p).');
});
