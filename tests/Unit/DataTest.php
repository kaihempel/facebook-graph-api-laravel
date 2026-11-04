<?php

declare(strict_types=1);

use KaiHempel\MetaGraph\Data\CursorPage;
use KaiHempel\MetaGraph\Data\InsightValue;
use KaiHempel\MetaGraph\Data\MediaState;
use KaiHempel\MetaGraph\Data\MediaStatus;
use KaiHempel\MetaGraph\Data\Post;
use KaiHempel\MetaGraph\Data\Video;
use KaiHempel\MetaGraph\Graph\GraphResponse;
use KaiHempel\MetaGraph\Resources\Posts\PostDraft;
use KaiHempel\MetaGraph\Support\Values;

it('keeps raw data on dtos', function () {
    $post = Post::fromArray(['id' => '1', 'message' => 'x', 'custom' => ['a' => 1]]);
    $video = Video::fromArray(['video_id' => '9', 'post_id' => '1_2']);

    expect($post->raw()['custom'])->toBe(['a' => 1])
        ->and($video->id)->toBe('9')
        ->and($video->postId)->toBe('1_2')
        ->and($video->raw())->toHaveKey('video_id');
});

it('parses edge responses into cursor pages', function () {
    $page = CursorPage::fromResponse(['data' => [['id' => 1], 'junk'], 'paging' => ['cursors' => ['before' => 'b', 'after' => 'a']]]);

    expect($page->items)->toBe([['id' => 1]])
        ->and($page->hasMore)->toBeFalse()
        ->and($page->nextCursor)->toBeNull()
        ->and($page->nextUrl())->toBeNull()
        ->and($page->isEmpty())->toBeFalse()
        ->and($page->raw())->toHaveKey('paging');
});

it('exposes graph responses', function () {
    $response = new GraphResponse(200, '{"data":[{"id":"1"}],"paging":{"next":"x"}}', ['X-FB-Trace-ID' => ['trace']]);

    expect($response->data())->toBe([['id' => '1']])
        ->and($response->paging())->toBe(['next' => 'x'])
        ->and($response->header('x-fb-trace-id'))->toBe('trace')
        ->and($response->header('missing'))->toBeNull()
        ->and($response->fbtraceId())->toBe('trace')
        ->and($response->id())->toBeNull()
        ->and(GraphResponse::fromArray(['error' => ['code' => 1]], 500)->successful())->toBeFalse()
        ->and((new GraphResponse(200, 'not json'))->raw())->toBe([]);
});

it('maps media status phases', function () {
    $uploading = MediaStatus::fromArray('1', ['status' => ['video_status' => 'uploading', 'uploading_phase' => ['status' => 'in_progress', 'bytes_transferred' => 7]]]);
    $uploadError = MediaStatus::fromArray('1', ['status' => ['uploading_phase' => ['status' => 'error', 'errors' => [['code' => 5, 'message' => 'broken']]]]]);
    $expired = MediaStatus::fromArray('1', ['status' => ['video_status' => 'expired']]);

    expect($uploading->bytesTransferred)->toBe(7)
        ->and($uploading->isUploadComplete())->toBeFalse()
        ->and($uploading->errorMessage())->toBeNull()
        ->and($uploadError->state)->toBe(MediaState::UploadFailed)
        ->and($uploadError->errorMessage())->toBe('broken')
        ->and($expired->errorMessage())->toBe('Media processing failed (expired).')
        ->and($expired->raw())->toHaveKey('status')
        ->and(MediaState::fromGraph('in_progress'))->toBe(MediaState::Processing)
        ->and(MediaState::fromGraph('weird'))->toBe(MediaState::Uploading)
        ->and(MediaState::Ready->isFinal())->toBeTrue()
        ->and(MediaState::Processing->isFinal())->toBeFalse();
});

it('builds immutable post drafts', function () {
    $draft = PostDraft::make('a');
    $other = $draft->message('b')->link('https://x.test')->with(['place' => '1']);

    expect($draft->toParams())->toBe(['message' => 'a'])
        ->and($other->toParams())->toBe(['message' => 'b', 'link' => 'https://x.test', 'place' => '1'])
        ->and($other->hasPhotos())->toBeFalse();
});

it('reads typed values defensively', function () {
    $data = ['s' => 'x', 'n' => '5', 'b' => 'true', 'f' => 0, 'd' => '2026-10-31T10:00:00+0000', 'bad' => 'no-date', 'ts' => 1700000000, 'nested' => ['a' => 'b']];

    expect(Values::string($data, 'nested.a'))->toBe('b')
        ->and(Values::string($data, 'b'))->toBe('true')
        ->and(Values::int($data, 'n'))->toBe(5)
        ->and(Values::bool($data, 'b'))->toBeTrue()
        ->and(Values::bool($data, 'f'))->toBeFalse()
        ->and(Values::bool($data, 's'))->toBeNull()
        ->and(Values::date($data, 'd')?->toDateString())->toBe('2026-10-31')
        ->and(Values::date($data, 'ts')?->getTimestamp())->toBe(1700000000)
        ->and(Values::date($data, 'bad'))->toBeNull()
        ->and(Values::date($data, 'missing'))->toBeNull()
        ->and(Values::strings(['l' => ['a', 1, null]], 'l'))->toBe(['a', '1'])
        ->and((new InsightValue('7'))->total())->toBe(7)
        ->and((new InsightValue(null))->total())->toBe(0);
});
