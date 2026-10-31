<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Kuweh\MetaGraph\Auth\TokenType;
use Kuweh\MetaGraph\Contracts\ListsTokens;
use Kuweh\MetaGraph\Events\TokenExpiringSoon;
use Kuweh\MetaGraph\Events\TokenInvalidated;
use Kuweh\MetaGraph\Exceptions\GraphException;
use Kuweh\MetaGraph\GraphManager;
use Kuweh\MetaGraph\Support\Dispatch;

/**
 * Prüft gespeicherte Tokens (für den Scheduler, täglich).
 */
final class CheckTokensCommand extends Command
{
    protected $signature = 'meta-graph:tokens:check {--app= : App aus der Config}';

    protected $description = 'Prüft gespeicherte Meta-Tokens und meldet ablaufende oder ungültige Tokens.';

    public function handle(GraphManager $manager): int
    {
        $app = $this->option('app');
        $manager = is_string($app) && $app !== '' ? $manager->app($app) : $manager;
        $repository = $manager->tokenRepository();

        if (! $repository instanceof ListsTokens) {
            $this->error('The configured token repository cannot list tokens ('.ListsTokens::class.').');

            return self::FAILURE;
        }

        $config = $manager->config();
        $tokens = is_array($config['tokens'] ?? null) ? $config['tokens'] : [];
        $days = is_numeric($tokens['expiring_soon_days'] ?? null) ? (int) $tokens['expiring_soon_days'] : 7;
        $threshold = CarbonImmutable::now()->addDays($days);
        $debugger = $manager->debugger();
        $rows = [];

        foreach ($repository->all() as $stored) {
            try {
                $info = $debugger->inspect($stored->token);
            } catch (GraphException $e) {
                $rows[] = [$stored->ownerType, $stored->ownerId, $stored->token->type->value, 'error: '.$e->getMessage()];

                continue;
            }

            if (! $info->isValid) {
                Dispatch::event(new TokenInvalidated($stored->token, $manager->appName(), null, $stored->ownerType, $stored->ownerId));
                $rows[] = [$stored->ownerType, $stored->ownerId, $stored->token->type->value, 'invalid'];

                continue;
            }

            $repository->markValidated($stored->ownerType, $stored->ownerId);
            $expiresAt = $info->expiresAt ?? $stored->token->expiresAt;

            if ($stored->token->type === TokenType::User && $expiresAt !== null && $expiresAt->lessThan($threshold)) {
                Dispatch::event(new TokenExpiringSoon($stored->ownerType, $stored->ownerId, $stored->token, $expiresAt));
                $rows[] = [$stored->ownerType, $stored->ownerId, $stored->token->type->value, 'expires '.$expiresAt->toDateTimeString()];

                continue;
            }

            $rows[] = [$stored->ownerType, $stored->ownerId, $stored->token->type->value, 'ok'];
        }

        $this->table(['Owner type', 'Owner ID', 'Token type', 'Status'], $rows);

        return self::SUCCESS;
    }
}
