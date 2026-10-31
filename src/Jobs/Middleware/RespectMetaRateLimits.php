<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Jobs\Middleware;

use Closure;
use Illuminate\Container\Container;
use Kuweh\MetaGraph\Exceptions\RateLimitException;
use Kuweh\MetaGraph\GraphManager;

/**
 * Stellt Jobs zurück, solange die App gesperrt ist bzw. ein Rate-Limit-Fehler auftritt.
 * Rate-Limit-Fehler werden nicht im selben Prozess wiederholt, da das die Sperre verlängert.
 */
final readonly class RespectMetaRateLimits
{
    public function __construct(
        private ?string $app = null,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        $manager = Container::getInstance()->make(GraphManager::class);
        $manager = $this->app === null ? $manager : $manager->app($this->app);

        $blockedFor = $manager->rateLimits()->blockedFor($manager->appConfig());

        if ($blockedFor > 0 && method_exists($job, 'release')) {
            $job->release($blockedFor);

            return null;
        }

        try {
            return $next($job);
        } catch (RateLimitException $e) {
            if (! method_exists($job, 'release')) {
                throw $e;
            }

            $job->release($e->retryAfter());

            return null;
        }
    }
}
