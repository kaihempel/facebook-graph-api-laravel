<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use KaiHempel\MetaGraph\Events\PostFailed;
use KaiHempel\MetaGraph\Events\PostPublished;
use KaiHempel\MetaGraph\Events\PostScheduled;
use KaiHempel\MetaGraph\Exceptions\DuplicatePostException;
use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\Media\MediaSource;
use KaiHempel\MetaGraph\Resources\Posts\PostDraft;
use KaiHempel\MetaGraph\Testing\GraphFake;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-31 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('creates text and link posts', function () {
    Event::fake([PostPublished::class]);
    $fake = MetaGraph::fake(['v26.0/111/feed' => ['id' => '111_1', 'message' => 'Hallo', 'created_time' => '2026-10-31T12:00:00+0000']]);

    $posts = MetaGraph::asPage($this->page())->posts();
    $post = $posts->create('Hallo');
    $posts->create(PostDraft::make()->message('Mit Link')->link('https://example.com')->with(['place' => '123']));

    expect($post->id)->toBe('111_1')
        ->and($post->message)->toBe('Hallo')
        ->and($post->isPublished)->toBeTrue()
        ->and($post->createdTime?->toDateString())->toBe('2026-10-31')
        ->and($post->isScheduled())->toBeFalse();

    $fake->assertPosted('v26.0/111/feed', fn (array $p) => $p['message'] === 'Hallo' && str_contains($p['fields'], 'is_published'));
    $fake->assertPosted('v26.0/111/feed', fn (array $p) => ($p['link'] ?? null) === 'https://example.com' && $p['place'] === '123');
    Event::assertDispatchedTimes(PostPublished::class, 2);
});

it('schedules posts with published=false and scheduled_publish_time', function () {
    Event::fake([PostScheduled::class]);
    $fake = MetaGraph::fake(['v26.0/111/feed' => ['id' => '111_2']]);
    $at = now()->addDays(2)->setTime(9, 0);

    $post = MetaGraph::asPage($this->page())->posts()->schedule('Später', $at);

    expect($post->id)->toBe('111_2')
        ->and($post->scheduledFor?->getTimestamp())->toBe($at->getTimestamp())
        ->and($post->isPublished)->toBeFalse()
        ->and($post->isScheduled())->toBeTrue();

    $fake->assertPosted('v26.0/111/feed', fn (array $p) => $p['published'] === 'false'
        && $p['scheduled_publish_time'] === (string) $at->getTimestamp());
    Event::assertDispatched(PostScheduled::class, fn (PostScheduled $e) => $e->pageId === '111');
});

it('validates the scheduling window', function (string $offset) {
    MetaGraph::fake();

    MetaGraph::asPage($this->page())->posts()->schedule('x', now()->modify($offset));
})->with(['+5 minutes', '+31 days', '-1 hour'])->throws(InvalidArgumentException::class);

it('respects a configured scheduling window', function () {
    config()->set('meta-graph.scheduling.max_lead_days', 180);
    MetaGraph::fake(['*' => ['id' => '1']]);

    expect(MetaGraph::asPage($this->page())->posts()->schedule('x', now()->addDays(90))->id)->toBe('1');
});

it('schedules multi photo posts in two steps', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('events/1.jpg', 'jpeg-1');
    Storage::disk('s3')->put('events/2.jpg', 'jpeg-2');

    $fake = MetaGraph::fake([
        'v26.0/111/photos' => GraphFake::sequence()->push(['id' => 'p1'])->push(['id' => 'p2']),
        'v26.0/111/feed' => ['id' => '111_3'],
    ]);

    $post = MetaGraph::asPage($this->page())->posts()->schedule(
        PostDraft::make()
            ->message('Drei Eindrücke von der Eröffnung')
            ->photos([
                MediaSource::disk('s3', 'events/1.jpg'),
                MediaSource::disk('s3', 'events/2.jpg'),
            ]),
        publishAt: now()->addDays(2)->setTime(9, 0),
    );

    expect($post->id)->toBe('111_3')
        ->and($post->scheduledFor)->toBeInstanceOf(CarbonImmutable::class);

    $fake->assertPosted('v26.0/111/photos', fn (array $p, $r) => $p['published'] === 'false'
        && $p['temporary'] === 'true'
        && $r->files === ['source' => '1.jpg']);
    $fake->assertPosted('v26.0/111/feed', fn (array $p) => $p['attached_media'] === '[{"media_fbid":"p1"},{"media_fbid":"p2"}]'
        && $p['published'] === 'false'
        && $p['message'] === 'Drei Eindrücke von der Eröffnung');
});

it('creates immediate multi photo posts via withPhotos', function () {
    $fake = MetaGraph::fake([
        'v26.0/111/photos' => GraphFake::sequence()->push(['id' => 'p1'])->push(['id' => 'p2']),
        'v26.0/111/feed' => ['id' => '111_4'],
    ]);

    MetaGraph::asPage($this->page())->posts()->withPhotos([
        MediaSource::url('https://cdn.example.com/1.jpg'),
        MediaSource::url('https://cdn.example.com/2.jpg'),
    ], 'Zwei Fotos');

    $fake->assertPosted('v26.0/111/photos', fn (array $p) => $p['url'] === 'https://cdn.example.com/1.jpg' && ! isset($p['temporary']));
    $fake->assertPosted('v26.0/111/feed', fn (array $p) => ! isset($p['published']) && str_contains($p['attached_media'], 'p2'));
});

it('reports uploaded photos when the feed post fails', function () {
    Event::fake([PostFailed::class]);
    MetaGraph::fake([
        'v26.0/111/photos' => ['id' => 'p1'],
        'v26.0/111/feed' => GraphFake::error(506, 'Duplicate status message'),
    ]);

    $draft = PostDraft::make('Dup')->photo(MediaSource::url('https://cdn.example.com/1.jpg'));

    expect(fn () => MetaGraph::asPage($this->page())->posts()->create($draft))->toThrow(DuplicatePostException::class);

    Event::assertDispatched(PostFailed::class, fn (PostFailed $e) => $e->uploadedPhotoIds === ['p1']
        && $e->exception instanceof DuplicatePostException
        && $e->draft === $draft);
});

it('updates, deletes and reads posts', function () {
    $fake = MetaGraph::fake([
        'v26.0/111_1' => fn (array $p, $r) => match ($r->method) {
            'GET' => GraphFake::response(['id' => '111_1', 'message' => 'Neu', 'is_published' => true, 'permalink_url' => 'https://fb.com/p']),
            default => GraphFake::response(['success' => true]),
        },
    ]);

    $posts = MetaGraph::asPage($this->page())->posts();

    expect($posts->update('111_1', 'Neu'))->toBeTrue()
        ->and($posts->update('111_1', PostDraft::make('Neu2')))->toBeTrue()
        ->and($posts->update('111_1', ['message' => 'Neu3']))->toBeTrue()
        ->and($posts->delete('111_1'))->toBeTrue()
        ->and($posts->find('111_1')->permalinkUrl)->toBe('https://fb.com/p');

    $fake->assertPosted('v26.0/111_1', fn (array $p) => $p['message'] === 'Neu3');
    $fake->assertDeleted('v26.0/111_1');
});

it('reads the feed including unpublished posts and scheduled posts', function () {
    $fake = MetaGraph::fake([
        'v26.0/111/feed' => GraphFake::paginated([
            [['id' => '1', 'is_published' => true], ['id' => '2', 'is_published' => false]],
            [['id' => '3', 'is_published' => true]],
        ]),
        'v26.0/111/scheduled_posts' => ['data' => [['id' => '9', 'scheduled_publish_time' => 1793520000, 'is_published' => false]]],
    ]);

    $posts = MetaGraph::asPage($this->page())->posts();
    $feed = $posts->feed(since: now()->subWeek(), until: now(), limit: 2)->all();
    $scheduled = $posts->scheduled()->all();

    expect($feed)->toHaveCount(3)
        ->and($feed[1]->isPublished)->toBeFalse()
        ->and($scheduled[0]->scheduledFor?->getTimestamp())->toBe(1793520000)
        ->and($scheduled[0]->isScheduled())->toBeTrue();

    $fake->assertFetched('v26.0/111/feed', fn (array $p) => $p['limit'] === '2' && isset($p['since'], $p['until']));
});
