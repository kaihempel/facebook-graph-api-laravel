<?php

declare(strict_types=1);

use KaiHempel\MetaGraph\Facades\MetaGraph;

/*
 * Optionale Live-Tests gegen eine eigene Testseite mit Test-App.
 * Laufen nur mit gesetzten Secrets: vendor/bin/pest --group=live
 */

beforeEach(function () {
    $required = ['META_LIVE_APP_ID', 'META_LIVE_APP_SECRET', 'META_LIVE_PAGE_ID', 'META_LIVE_PAGE_TOKEN'];

    foreach ($required as $name) {
        if ((string) getenv($name) === '') {
            $this->markTestSkipped("Live test requires {$name}.");
        }
    }

    config()->set('meta-graph.apps.main.app_id', getenv('META_LIVE_APP_ID'));
    config()->set('meta-graph.apps.main.app_secret', getenv('META_LIVE_APP_SECRET'));
});

it('reads the test page', function () {
    $page = MetaGraph::asPage((string) getenv('META_LIVE_PAGE_TOKEN'))->pages()->find((string) getenv('META_LIVE_PAGE_ID'));

    expect($page->id)->toBe((string) getenv('META_LIVE_PAGE_ID'));
})->group('live');

it('debugs the page token', function () {
    $info = MetaGraph::debugger()->inspect((string) getenv('META_LIVE_PAGE_TOKEN'));

    expect($info->isValid)->toBeTrue();
})->group('live');
