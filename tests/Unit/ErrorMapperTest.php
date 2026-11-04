<?php

declare(strict_types=1);

use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Data\RateLimitUsage;
use KaiHempel\MetaGraph\Exceptions\AuthenticationException;
use KaiHempel\MetaGraph\Exceptions\DuplicatePostException;
use KaiHempel\MetaGraph\Exceptions\ErrorMapper;
use KaiHempel\MetaGraph\Exceptions\GraphException;
use KaiHempel\MetaGraph\Exceptions\LinkScrapeException;
use KaiHempel\MetaGraph\Exceptions\PageRoleException;
use KaiHempel\MetaGraph\Exceptions\PermissionException;
use KaiHempel\MetaGraph\Exceptions\PolicyBlockException;
use KaiHempel\MetaGraph\Exceptions\RateLimitException;
use KaiHempel\MetaGraph\Exceptions\TransientException;
use KaiHempel\MetaGraph\Exceptions\UserActionRequiredException;
use KaiHempel\MetaGraph\Graph\GraphRequest;
use KaiHempel\MetaGraph\Graph\GraphResponse;

it('maps every documented code to exactly one exception', function (int $code, ?int $subcode, string $type, string $expected) {
    $exception = ErrorMapper::fromError(array_filter([
        'message' => 'Error',
        'type' => $type,
        'code' => $code,
        'error_subcode' => $subcode,
    ], fn ($v) => $v !== null));

    expect($exception)->toBeInstanceOf($expected)
        ->and($exception::class)->toBe($expected);
})->with([
    'code 1 transient' => [1, null, 'OAuthException', TransientException::class],
    'code 2 transient' => [2, null, 'OAuthException', TransientException::class],
    'code 4 app limit' => [4, null, 'OAuthException', RateLimitException::class],
    'code 17 user limit' => [17, null, 'OAuthException', RateLimitException::class],
    'code 32 page limit' => [32, null, 'OAuthException', RateLimitException::class],
    'code 341' => [341, null, 'OAuthException', RateLimitException::class],
    'code 613' => [613, null, 'OAuthException', RateLimitException::class],
    'code 80001 buc' => [80001, null, 'OAuthException', RateLimitException::class],
    'code 102' => [102, null, 'OAuthException', AuthenticationException::class],
    'code 190' => [190, null, 'OAuthException', AuthenticationException::class],
    'oauth without subcode' => [999, null, 'OAuthException', AuthenticationException::class],
    'subcode 458' => [190, 458, 'OAuthException', AuthenticationException::class],
    'subcode 463 expired' => [190, 463, 'OAuthException', AuthenticationException::class],
    'subcode 467 invalid' => [190, 467, 'OAuthException', AuthenticationException::class],
    'subcode 459 checkpoint' => [190, 459, 'OAuthException', UserActionRequiredException::class],
    'subcode 460 password' => [190, 460, 'OAuthException', UserActionRequiredException::class],
    'subcode 464 unconfirmed' => [190, 464, 'OAuthException', UserActionRequiredException::class],
    'subcode 492 page role' => [190, 492, 'OAuthException', PageRoleException::class],
    'code 3' => [3, null, 'OAuthException', PermissionException::class],
    'code 10' => [10, null, 'OAuthException', PermissionException::class],
    'code 200' => [200, null, 'OAuthException', PermissionException::class],
    'code 230' => [230, null, 'OAuthException', PermissionException::class],
    'code 299' => [299, null, 'OAuthException', PermissionException::class],
    'code 368 policy' => [368, null, 'OAuthException', PolicyBlockException::class],
    'code 506 duplicate' => [506, null, 'OAuthException', DuplicatePostException::class],
    'subcode 1609005 link' => [100, 1609005, 'OAuthException', LinkScrapeException::class],
    'code 100 invalid param' => [100, null, 'OAuthException', GraphException::class],
    'code 100 method' => [100, 33, 'GraphMethodException', GraphException::class],
    'unknown' => [12345, null, 'GraphMethodException', GraphException::class],
]);

it('distinguishes retry, reauthenticate and change content', function () {
    expect(ErrorMapper::fromError(['code' => 2])->isTransient())->toBeTrue()
        ->and(ErrorMapper::fromError(['code' => 190])->shouldReauthenticate())->toBeTrue()
        ->and(ErrorMapper::fromError(['code' => 190, 'error_subcode' => 460])->shouldReauthenticate())->toBeTrue()
        ->and(ErrorMapper::fromError(['code' => 506])->isTransient())->toBeFalse()
        ->and(ErrorMapper::fromError(['code' => 506])->shouldReauthenticate())->toBeFalse()
        ->and(ErrorMapper::fromError(['code' => 4])->isTransient())->toBeFalse()
        ->and(ErrorMapper::fromError(['code' => 99, 'type' => 'Foo', 'is_transient' => true]))->toBeInstanceOf(TransientException::class);
});

it('exposes all error properties and hides the token', function () {
    $request = GraphRequest::get('me', ['fields' => 'id'])->withToken(AccessToken::user('EAAGsupersecrettoken'));

    $exception = ErrorMapper::fromError([
        'message' => 'Invalid OAuth access token.',
        'type' => 'OAuthException',
        'code' => 190,
        'error_subcode' => 463,
        'error_user_title' => 'Session expired',
        'error_user_msg' => 'Please log in again.',
        'fbtrace_id' => 'Abc123',
    ], 401, $request);

    expect($exception->getMessage())->toBe('Invalid OAuth access token.')
        ->and($exception->type)->toBe('OAuthException')
        ->and($exception->getCode())->toBe(190)
        ->and($exception->subcode)->toBe(463)
        ->and($exception->subcode())->toBe(463)
        ->and($exception->userTitle)->toBe('Session expired')
        ->and($exception->userMessage)->toBe('Please log in again.')
        ->and($exception->fbtraceId)->toBe('Abc123')
        ->and($exception->httpStatus)->toBe(401)
        ->and($exception->request['url'])->toBe('https://graph.facebook.com/v26.0/me')
        ->and(json_encode($exception->request))->not->toContain('EAAGsupersecrettoken')
        ->and($exception->context()['code'])->toBe(190);
});

it('maps responses without error payload by http status', function () {
    $server = ErrorMapper::fromResponse(new GraphResponse(503, 'Service unavailable'));
    $client = ErrorMapper::fromResponse(new GraphResponse(404, '<html>'));

    expect($server)->toBeInstanceOf(TransientException::class)
        ->and($server->httpStatus)->toBe(503)
        ->and($client::class)->toBe(GraphException::class);
});

it('derives the retry delay of rate limit errors from estimated_time_to_regain_access', function () {
    $usage = RateLimitUsage::fromHeaders([
        'X-Business-Use-Case-Usage' => json_encode(['42' => [['type' => 'pages', 'call_count' => 100, 'estimated_time_to_regain_access' => 7]]]),
    ]);

    $withUsage = ErrorMapper::fromError(['code' => 80001], 400, null, $usage);
    $without = ErrorMapper::fromError(['code' => 4]);

    expect($withUsage)->toBeInstanceOf(RateLimitException::class)
        ->and($withUsage->retryAfter())->toBe(420)
        ->and($without->retryAfter())->toBe(RateLimitException::DEFAULT_RETRY_AFTER);
});

it('extracts the missing scope from permission errors', function (string $message, ?string $scope) {
    $exception = ErrorMapper::fromError(['code' => 200, 'message' => $message]);

    expect($exception)->toBeInstanceOf(PermissionException::class)
        ->and($exception->missingScope())->toBe($scope);
})->with([
    ['(#200) Requires pages_manage_posts permission to manage the object', 'pages_manage_posts'],
    ['(#10) This endpoint requires the pages_read_engagement permission', 'pages_read_engagement'],
    ['(#200) Missing permission(s) publish_video', 'publish_video'],
    ['(#200) Permissions error', null],
]);

it('provides a hint for user actions', function (int $subcode, string $contains) {
    $exception = ErrorMapper::fromError(['code' => 190, 'error_subcode' => $subcode]);

    expect($exception)->toBeInstanceOf(UserActionRequiredException::class)
        ->and($exception->hint())->toContain($contains);
})->with([
    [460, 'Passwort'],
    [459, 'Checkpoint'],
    [464, 'bestätigt'],
]);
