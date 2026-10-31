<?php

declare(strict_types=1);

use Kuweh\MetaGraph\Data\MediaState;
use Kuweh\MetaGraph\Data\ReelUpload;
use Kuweh\MetaGraph\Exceptions\TransientException;
use Kuweh\MetaGraph\Facades\MetaGraph;
use Kuweh\MetaGraph\Media\MediaSource;
use Kuweh\MetaGraph\Testing\GraphFake;

it('publishes reels in three phases', function () {
    $fake = MetaGraph::fake([
        'v26.0/*/video_reels' => GraphFake::sequence()
            ->push(['video_id' => '999', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/999'])
            ->push(['success' => true]),
        'rupload.facebook.com/video-upload/v26.0/999' => ['success' => true],
    ]);

    $path = tempnam(sys_get_temp_dir(), 'reel');
    file_put_contents($path, 'reel-bytes');

    $reel = MetaGraph::asPage($this->page())->reels()->publish(
        MediaSource::file($path, 'reel.mp4', 'video/mp4'),
        description: 'Neues Reel #laravel',
        title: 'Reel',
    );

    expect($reel->id)->toBe('999')->and($reel->description)->toBe('Neues Reel #laravel');

    $fake->assertPosted('v26.0/111/video_reels', fn (array $p) => $p['upload_phase'] === 'start' && $p['access_token'] === 'page-token-111');
    $fake->assertPosted('rupload.facebook.com/*', fn (array $p, $r) => $r->header('Authorization') === 'OAuth page-token-111'
        && $r->header('offset') === '0'
        && $r->header('file_size') === '10'
        && $r->request->body() === 'reel-bytes'
        && ! isset($p['access_token']) && ! isset($p['appsecret_proof']));
    $fake->assertPosted('v26.0/111/video_reels', fn (array $p) => $p['upload_phase'] === 'finish'
        && $p['video_id'] === '999'
        && $p['video_state'] === 'PUBLISHED'
        && $p['description'] === 'Neues Reel #laravel'
        && $p['title'] === 'Reel');

    unlink($path);
});

it('uploads hosted files via the file_url header', function () {
    $fake = MetaGraph::fake(['rupload.facebook.com/*' => ['success' => true]]);

    MetaGraph::asPage($this->page())->reels()->upload(
        new ReelUpload('999', 'https://rupload.facebook.com/video-upload/v26.0/999'),
        MediaSource::url('https://cdn.example.com/reel.mp4'),
    );

    $fake->assertPosted('rupload.facebook.com/video-upload/v26.0/999', fn (array $p, $r) => $r->header('file_url') === 'https://cdn.example.com/reel.mp4');
});

it('builds the upload url when meta omits it', function () {
    MetaGraph::fake(['v26.0/me/video_reels' => ['video_id' => '42']]);

    expect(MetaGraph::asPage('token')->reels()->start()->uploadUrl)->toBe('https://rupload.facebook.com/video-upload/v26.0/42');
});

it('resumes aborted binary uploads at bytes_transfered', function () {
    $fake = MetaGraph::fake([
        'rupload.facebook.com/*' => GraphFake::sequence()->pushError(2, 'Connection lost', status: 500)->push(['success' => true]),
        'v26.0/999' => ['id' => '999', 'status' => ['video_status' => 'uploading', 'uploading_phase' => ['status' => 'in_progress', 'bytes_transfered' => 4]]],
    ]);

    MetaGraph::asPage($this->page())->reels()->upload(
        new ReelUpload('999', 'https://rupload.facebook.com/video-upload/v26.0/999'),
        MediaSource::contents('0123456789', 'r.mp4', 'video/mp4'),
    );

    $fake->assertPosted('rupload.facebook.com/*', fn (array $p, $r) => $r->header('offset') === '4' && $r->request->body() === '456789');
});

it('checks the status instead of blindly retrying finish', function () {
    MetaGraph::fake([
        'v26.0/111/video_reels' => GraphFake::error(2, 'Timeout', status: 500),
        'v26.0/999' => ['status' => ['video_status' => 'processing', 'publishing_phase' => ['status' => 'in_progress']]],
        'v26.0/998' => ['status' => ['video_status' => 'upload_complete', 'publishing_phase' => ['status' => 'not_started']]],
    ]);

    $reels = MetaGraph::asPage($this->page())->reels();

    expect($reels->finish('999', 'desc'))->toBeTrue()
        ->and(fn () => $reels->finish('998'))->toThrow(TransientException::class);
});

it('maps all reel states', function (string $status, MediaState $state) {
    MetaGraph::fake(['v26.0/1' => ['status' => ['video_status' => $status]]]);

    expect(MetaGraph::asPage('t')->reels()->status('1')->state)->toBe($state);
})->with([
    ['uploading', MediaState::Uploading],
    ['upload_complete', MediaState::UploadComplete],
    ['upload_failed', MediaState::UploadFailed],
    ['processing', MediaState::Processing],
    ['ready', MediaState::Ready],
    ['error', MediaState::Error],
    ['expired', MediaState::Expired],
    ['published', MediaState::Ready],
]);

it('schedules reels when requested', function () {
    $fake = MetaGraph::fake(['v26.0/111/video_reels' => ['success' => true]]);

    MetaGraph::asPage($this->page())->reels()->finish('1', scheduledAt: now()->addDay());

    $fake->assertPosted('*/video_reels', fn (array $p) => $p['video_state'] === 'SCHEDULED' && isset($p['scheduled_publish_time']));
});
