<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Facades;

use Illuminate\Support\Facades\Facade;
use Kuweh\MetaGraph\GraphManager;

/**
 * @method static GraphManager app(string $name)
 * @method static string appName()
 * @method static \Kuweh\MetaGraph\AppConfig appConfig()
 * @method static \Kuweh\MetaGraph\Graph\GraphClient asPage(\Kuweh\MetaGraph\Data\PageConnection|\Kuweh\MetaGraph\Auth\AccessToken|string $pageOrToken)
 * @method static \Kuweh\MetaGraph\Graph\GraphClient asStoredPage(string $pageId)
 * @method static \Kuweh\MetaGraph\Graph\GraphClient asUser(\Kuweh\MetaGraph\Auth\AccessToken|string $token)
 * @method static \Kuweh\MetaGraph\Graph\GraphClient asApp()
 * @method static \Kuweh\MetaGraph\Graph\GraphClient asSystemUser(\Kuweh\MetaGraph\Auth\AccessToken|string $token)
 * @method static \Kuweh\MetaGraph\Graph\GraphClient client(?\Kuweh\MetaGraph\Auth\AccessToken $token = null, ?string $pageId = null)
 * @method static \Kuweh\MetaGraph\Auth\OAuth\LoginFlow login()
 * @method static \Kuweh\MetaGraph\Auth\TokenExchanger tokens()
 * @method static \Kuweh\MetaGraph\Auth\TokenDebugger debugger()
 * @method static \Kuweh\MetaGraph\Contracts\TokenRepository tokenRepository()
 * @method static void connectPage(\Kuweh\MetaGraph\Data\PageConnection $page)
 * @method static void disconnectPage(string $pageId)
 * @method static \Kuweh\MetaGraph\Http\RateLimitTracker rateLimits()
 * @method static \Kuweh\MetaGraph\Testing\GraphFake fake(array<string, mixed> $stubs = [])
 *
 * @see GraphManager
 */
final class MetaGraph extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GraphManager::class;
    }
}
