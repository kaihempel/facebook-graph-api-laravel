<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Http;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Kuweh\MetaGraph\AppConfig;
use Kuweh\MetaGraph\Data\AppUsage;
use Kuweh\MetaGraph\Data\BusinessUseCaseUsage;
use Kuweh\MetaGraph\Data\RateLimitUsage;
use Kuweh\MetaGraph\Events\RateLimitApproaching;
use Kuweh\MetaGraph\Events\RateLimitHit;
use Kuweh\MetaGraph\Exceptions\RateLimitException;
use Kuweh\MetaGraph\Support\Dispatch;

/**
 * Wertet X-App-Usage und X-Business-Use-Case-Usage nach jeder Antwort aus
 * und hält den Stand pro App bzw. Business-Objekt im Laravel-Cache.
 */
final class RateLimitTracker
{
    private const string PREFIX = 'meta-graph:rate-limit:';

    private const int TTL = 3600;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ?string $store = null,
        private readonly int $warnAt = 80,
    ) {}

    public function record(AppConfig $app, RateLimitUsage $usage): void
    {
        if ($usage->isEmpty()) {
            return;
        }

        $previous = $this->current($app);

        if ($usage->app !== null) {
            $this->repository()->put($this->key('app', $app->appId), $usage->app->toArray(), self::TTL);
        }

        foreach ($usage->businessUseCases as $buc) {
            $this->repository()->put($this->key('buc', $app->appId.':'.$buc->businessId.':'.$buc->type), $buc->toArray(), self::TTL);
            $this->rememberBusiness($app, $buc->businessId.':'.$buc->type);

            if ($buc->estimatedTimeToRegainAccess > 0) {
                $this->block($app, $buc->secondsToRegainAccess());
            }
        }

        if ($usage->isApproaching($this->warnAt) && ! $previous->isApproaching($this->warnAt)) {
            Dispatch::event(new RateLimitApproaching($app->name, $usage, $this->warnAt));
        }
    }

    public function hit(AppConfig $app, RateLimitException $exception): void
    {
        $this->block($app, $exception->retryAfter());

        Dispatch::event(new RateLimitHit($app->name, $exception));
    }

    /**
     * Zuletzt gemeldete Auslastung der App über alle bekannten Business-Objekte.
     */
    public function current(AppConfig $app): RateLimitUsage
    {
        $appData = $this->repository()->get($this->key('app', $app->appId));
        $business = [];

        foreach ($this->businessKeys($app) as $key) {
            $data = $this->repository()->get($this->key('buc', $app->appId.':'.$key));

            if (is_array($data)) {
                $id = $data['business_id'] ?? '';
                $business[] = BusinessUseCaseUsage::fromArray(is_scalar($id) ? (string) $id : '', $data);
            }
        }

        return new RateLimitUsage(is_array($appData) ? AppUsage::fromArray($appData) : null, $business);
    }

    /**
     * Verbleibende Sperrzeit in Sekunden (0 = nicht gesperrt).
     */
    public function blockedFor(AppConfig $app): int
    {
        $until = $this->repository()->get($this->key('blocked', $app->appId));
        $remaining = is_numeric($until) ? (int) $until - time() : 0;

        if ($remaining <= 0 && $this->current($app)->max() >= 100) {
            return RateLimitException::DEFAULT_RETRY_AFTER;
        }

        return max(0, $remaining);
    }

    public function block(AppConfig $app, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $key = $this->key('blocked', $app->appId);
        $until = time() + $seconds;
        $existing = $this->repository()->get($key);

        if (! is_numeric($existing) || (int) $existing < $until) {
            $this->repository()->put($key, $until, $seconds);
        }
    }

    public function reset(AppConfig $app): void
    {
        $this->repository()->forget($this->key('app', $app->appId));
        $this->repository()->forget($this->key('blocked', $app->appId));

        foreach ($this->businessKeys($app) as $key) {
            $this->repository()->forget($this->key('buc', $app->appId.':'.$key));
        }

        $this->repository()->forget($this->key('businesses', $app->appId));
    }

    public function warnAt(): int
    {
        return $this->warnAt;
    }

    private function rememberBusiness(AppConfig $app, string $key): void
    {
        $keys = $this->businessKeys($app);

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            $this->repository()->put($this->key('businesses', $app->appId), $keys, self::TTL);
        }
    }

    /**
     * @return list<string>
     */
    private function businessKeys(AppConfig $app): array
    {
        $keys = $this->repository()->get($this->key('businesses', $app->appId));

        return is_array($keys) ? array_values(array_filter($keys, is_string(...))) : [];
    }

    private function key(string $type, string $id): string
    {
        return self::PREFIX.$type.':'.$id;
    }

    private function repository(): Cache
    {
        return $this->cache->store($this->store);
    }
}
