<?php

declare(strict_types=1);

use Kuweh\MetaGraph\Data\Comment;
use Kuweh\MetaGraph\Data\MediaStatus;
use Kuweh\MetaGraph\Data\PageConnection;
use Kuweh\MetaGraph\Data\Post;
use Kuweh\MetaGraph\Exceptions\ErrorMapper;
use Kuweh\MetaGraph\Exceptions\PermissionException;
use Kuweh\MetaGraph\Exceptions\RateLimitException;
use Kuweh\MetaGraph\Exceptions\UserActionRequiredException;
use Kuweh\MetaGraph\Facades\MetaGraph;
use Kuweh\MetaGraph\Testing\GraphFake;

/*
 * Regressionstests gegen aufgezeichnete, anonymisierte Antworten aus dem Graph API Explorer.
 */

it('parses /me/accounts', function () {
    MetaGraph::fake(['v26.0/me/accounts' => GraphFake::response(fixture('me_accounts'))]);

    $pages = MetaGraph::asUser('t')->pages()->connected();

    expect($pages)->toHaveCount(2)
        ->and($pages->first())->toBeInstanceOf(PageConnection::class)
        ->and($pages->first()?->tasks)->toContain('CREATE_CONTENT')
        ->and($pages->last()?->canCreateContent())->toBeFalse();
});

it('parses the page feed', function () {
    $posts = array_map(Post::fromArray(...), fixture('page_feed')['data']);

    expect($posts[0]->id)->toBe('111_1001')
        ->and($posts[0]->isPublished)->toBeTrue()
        ->and($posts[0]->permalinkUrl)->toStartWith('https://www.facebook.com/')
        ->and($posts[1]->isScheduled())->toBeTrue()
        ->and($posts[1]->scheduledFor?->getTimestamp())->toBe(1793952000);
});

it('parses comments', function () {
    $comments = array_map(Comment::fromArray(...), fixture('post_comments')['data']);

    expect($comments[0]->fromName)->toBe('Erika Mustermann')
        ->and($comments[0]->commentCount)->toBe(1)
        ->and($comments[1]->isHidden)->toBeTrue();
});

it('parses reel status responses', function () {
    $status = MediaStatus::fromArray('999', fixture('reel_status_processing'));

    expect($status->isUploadComplete())->toBeTrue()
        ->and($status->processingPhase)->toBe('in_progress')
        ->and($status->publishingPhase)->toBe('not_started')
        ->and($status->isFailed())->toBeFalse();
});

it('maps recorded error payloads', function (string $fixture, string $class) {
    $error = fixture($fixture)['error'];

    expect(ErrorMapper::fromError($error))->toBeInstanceOf($class);
})->with([
    ['error_permission', PermissionException::class],
    ['error_page_rate_limit', RateLimitException::class],
    ['error_password_changed', UserActionRequiredException::class],
]);
