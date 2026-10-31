<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Kuweh\MetaGraph\Events\PageCommentReceived;
use Kuweh\MetaGraph\Events\PageFeedChanged;
use Kuweh\MetaGraph\Events\PageMentionReceived;
use Kuweh\MetaGraph\Events\WebhookReceived;
use Kuweh\MetaGraph\Jobs\ProcessWebhookPayload;
use Kuweh\MetaGraph\Tests\TestCase;
use Kuweh\MetaGraph\Webhooks\SignatureVerifier;
use Kuweh\MetaGraph\Webhooks\WebhookPayload;

function postWebhook(TestCase $test, string $body, ?string $signature = null, string $uri = '/meta-graph/webhook')
{
    return $test->call('POST', $uri, [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature ?? SignatureVerifier::sign($body, TestCase::APP_SECRET),
    ]), $body);
}

it('answers the verification handshake', function () {
    $this->get('/meta-graph/webhook?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1158201444')
        ->assertOk()
        ->assertSeeText('1158201444')
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
});

it('rejects the handshake with a wrong verify token', function (string $query) {
    $this->get('/meta-graph/webhook?'.$query)->assertForbidden();
})->with([
    'wrong token' => 'hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1',
    'wrong mode' => 'hub.mode=unsubscribe&hub.verify_token=verify-me&hub.challenge=1',
    'missing' => '',
]);

it('rejects the handshake when no verify token is configured', function () {
    config()->set('meta-graph.webhooks.verify_token', null);

    $this->get('/meta-graph/webhook?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
});

it('verifies the signature on the raw body and queues the payload', function () {
    Bus::fake();
    config()->set('meta-graph.webhooks.queue', 'webhooks');
    config()->set('meta-graph.webhooks.connection', 'sync');
    $body = json_encode(fixture('webhook_page_feed'), JSON_PRETTY_PRINT);

    postWebhook($this, $body)->assertOk()->assertSeeText('EVENT_RECEIVED');

    Bus::assertDispatched(ProcessWebhookPayload::class, fn (ProcessWebhookPayload $job) => $job->payload['object'] === 'page'
        && $job->app === 'main'
        && $job->queue === 'webhooks'
        && $job->connection === 'sync');
});

it('rejects invalid signatures, sha1 signatures and invalid payloads', function () {
    Bus::fake();
    $body = '{"object":"page","entry":[]}';

    postWebhook($this, $body, 'sha256=invalid')->assertForbidden();
    postWebhook($this, $body, 'sha1='.hash_hmac('sha1', $body, TestCase::APP_SECRET))->assertForbidden();
    postWebhook($this, 'not-json')->assertStatus(400);

    Bus::assertNothingDispatched();
});

it('supports app specific webhook urls', function () {
    Bus::fake();
    $body = '{"object":"page","entry":[]}';

    postWebhook($this, $body, SignatureVerifier::sign($body, 'secret-b'), '/meta-graph/webhook/kunde-b')->assertOk();
    postWebhook($this, $body, null, '/meta-graph/webhook/unknown')->assertNotFound();

    Bus::assertDispatched(ProcessWebhookPayload::class, fn ($job) => $job->app === 'kunde-b');
});

it('is excluded from csrf protection', function () {
    Event::fake();
    $body = json_encode(fixture('webhook_page_feed'));

    // Kein Session-/CSRF-Token: muss trotzdem angenommen und synchron verarbeitet werden.
    postWebhook($this, $body)->assertOk();

    Event::assertDispatched(WebhookReceived::class);
});

it('splits payloads into changes', function () {
    $changes = WebhookPayload::parse(fixture('webhook_page_feed'));

    expect($changes)->toHaveCount(3)
        ->and($changes[0]->objectType)->toBe('page')
        ->and($changes[0]->objectId)->toBe('111')
        ->and($changes[0]->field)->toBe('feed')
        ->and($changes[0]->item())->toBe('status')
        ->and($changes[0]->verb())->toBe('add')
        ->and($changes[0]->get('post_id'))->toBe('111_222')
        ->and($changes[0]->time?->getTimestamp())->toBe(1793534400)
        ->and($changes[1]->item())->toBe('comment')
        ->and($changes[2]->field)->toBe('mention')
        ->and($changes[0]->toArray()['id'])->toBe('111')
        ->and(WebhookPayload::parse(['object' => 'page', 'entry' => ['x', ['id' => '1', 'changes' => ['y', ['field' => 'feed', 'value' => 'scalar']]]]])[0]->value)
        ->toBe(['value' => 'scalar']);
});

it('fires generic and typed events and deduplicates replays', function () {
    Event::fake();
    $job = new ProcessWebhookPayload(fixture('webhook_page_feed'), 'main');

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    Event::assertDispatchedTimes(WebhookReceived::class, 3);
    Event::assertDispatchedTimes(PageFeedChanged::class, 2);
    Event::assertDispatchedTimes(PageCommentReceived::class, 1);
    Event::assertDispatchedTimes(PageMentionReceived::class, 1);
    Event::assertDispatched(PageCommentReceived::class, fn ($e) => $e->change->get('comment_id') === '222_333');
});

it('only fires the generic event for non page objects', function () {
    Event::fake();

    app()->call([new ProcessWebhookPayload(['object' => 'user', 'entry' => [['id' => '1', 'time' => 1, 'changes' => [['field' => 'feed', 'value' => ['a' => 1]]]]]]), 'handle']);

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
    Event::assertNotDispatched(PageFeedChanged::class);
});
