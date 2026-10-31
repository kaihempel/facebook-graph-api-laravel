<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kuweh\MetaGraph\GraphManager;
use Kuweh\MetaGraph\Jobs\Middleware\RespectMetaRateLimits;

/**
 * Liest die Page Tokens eines gespeicherten Long-lived User Tokens neu ein und speichert sie.
 */
final class RefreshPageTokens implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $userId,
        public readonly ?string $app = null,
    ) {}

    public function handle(GraphManager $manager): void
    {
        $manager = $this->app === null ? $manager : $manager->app($this->app);
        $token = $manager->tokenRepository()->find(GraphManager::OWNER_USER, $this->userId);

        if ($token === null) {
            $this->fail(new \RuntimeException("No stored user token for [{$this->userId}]."));

            return;
        }

        foreach ($manager->tokens()->pageTokens($token) as $page) {
            $manager->connectPage($page);
        }
    }

    public function uniqueId(): string
    {
        return ($this->app ?? 'default').':'.$this->userId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectMetaRateLimits($this->app)];
    }
}
