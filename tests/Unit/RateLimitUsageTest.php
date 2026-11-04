<?php

declare(strict_types=1);

use KaiHempel\MetaGraph\Data\RateLimitUsage;

it('parses X-App-Usage', function () {
    $usage = RateLimitUsage::fromHeaders([
        'x-app-usage' => ['{"call_count":28,"total_time":25,"total_cputime":81}'],
    ]);

    expect($usage->app?->callCount)->toBe(28)
        ->and($usage->app?->totalTime)->toBe(25)
        ->and($usage->app?->totalCputime)->toBe(81)
        ->and($usage->max())->toBe(81)
        ->and($usage->isApproaching(80))->toBeTrue()
        ->and($usage->isApproaching(90))->toBeFalse();
});

it('parses X-Business-Use-Case-Usage for several business objects', function () {
    $usage = RateLimitUsage::fromHeaders([
        'X-Business-Use-Case-Usage' => json_encode([
            '112130216863063' => [
                ['type' => 'pages', 'call_count' => 100, 'total_cputime' => 25, 'total_time' => 25, 'estimated_time_to_regain_access' => 19],
            ],
            '999' => [
                ['type' => 'pages', 'call_count' => 3, 'total_cputime' => 1, 'total_time' => 2, 'estimated_time_to_regain_access' => 0],
            ],
        ]),
    ]);

    expect($usage->businessUseCases)->toHaveCount(2)
        ->and($usage->businessUseCases[0]->businessId)->toBe('112130216863063')
        ->and($usage->businessUseCases[0]->type)->toBe('pages')
        ->and($usage->businessUseCases[0]->estimatedTimeToRegainAccess)->toBe(19)
        ->and($usage->secondsToRegainAccess())->toBe(19 * 60)
        ->and($usage->max())->toBe(100)
        ->and($usage->toArray()['business_use_cases'][1]['business_id'])->toBe('999');
});

it('ignores missing or malformed headers', function () {
    $usage = RateLimitUsage::fromHeaders(['X-App-Usage' => 'not-json', 'Other' => 'x']);

    expect($usage->isEmpty())->toBeTrue()
        ->and($usage->max())->toBe(0)
        ->and($usage->isApproaching(80))->toBeFalse();
});
