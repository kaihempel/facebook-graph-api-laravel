<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kuweh\MetaGraph\Http\Controllers\WebhookController;

/** @var array<array-key, string> $middleware */
$middleware = config('meta-graph.webhooks.middleware', []);
$configuredPath = config('meta-graph.webhooks.path');
$path = is_string($configuredPath) && $configuredPath !== '' ? trim($configuredPath, '/') : 'meta-graph/webhook';

Route::middleware($middleware)->group(function () use ($path): void {
    Route::get($path.'/{app?}', [WebhookController::class, 'verify'])->name('meta-graph.webhook.verify');
    Route::post($path.'/{app?}', [WebhookController::class, 'handle'])->name('meta-graph.webhook.handle');
});
