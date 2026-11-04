<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Facades;

use Illuminate\Support\Facades\Facade;
use KaiHempel\MetaGraph\GraphManager;

/**
 * @method static GraphManager app(string $name)
 * @method static string appName()
 * @method static \KaiHempel\MetaGraph\AppConfig appConfig()
 * @method static \KaiHempel\MetaGraph\Graph\GraphClient asPage(\KaiHempel\MetaGraph\Data\PageConnection|\KaiHempel\MetaGraph\Auth\AccessToken|string $pageOrToken)
 * @method static \KaiHempel\MetaGraph\Graph\GraphClient asStoredPage(string $pageId)
 * @method static \KaiHempel\MetaGraph\Graph\GraphClient asUser(\KaiHempel\MetaGraph\Auth\AccessToken|string $token)
 * @method static \KaiHempel\MetaGraph\Graph\GraphClient asApp()
 * @method static \KaiHempel\MetaGraph\Graph\GraphClient asSystemUser(\KaiHempel\MetaGraph\Auth\AccessToken|string $token)
 * @method static \KaiHempel\MetaGraph\Graph\GraphClient client(?\KaiHempel\MetaGraph\Auth\AccessToken $token = null, ?string $pageId = null)
 * @method static \KaiHempel\MetaGraph\Auth\OAuth\LoginFlow login()
 * @method static \KaiHempel\MetaGraph\Auth\TokenExchanger tokens()
 * @method static \KaiHempel\MetaGraph\Auth\TokenDebugger debugger()
 * @method static \KaiHempel\MetaGraph\Contracts\TokenRepository tokenRepository()
 * @method static void connectPage(\KaiHempel\MetaGraph\Data\PageConnection $page)
 * @method static void disconnectPage(string $pageId)
 * @method static \KaiHempel\MetaGraph\Http\RateLimitTracker rateLimits()
 * @method static \KaiHempel\MetaGraph\Testing\GraphFake fake(array<string, mixed> $stubs = [])
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
