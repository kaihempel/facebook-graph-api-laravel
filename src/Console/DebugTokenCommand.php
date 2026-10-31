<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Console;

use Illuminate\Console\Command;
use Kuweh\MetaGraph\Exceptions\GraphException;
use Kuweh\MetaGraph\GraphManager;

final class DebugTokenCommand extends Command
{
    protected $signature = 'meta-graph:token:debug {token? : Zu prüfender Token} {--app= : App aus der Config}';

    protected $description = 'Zeigt Gültigkeit, Ablauf, Scopes und Typ eines Tokens (GET /debug_token).';

    public function handle(GraphManager $manager): int
    {
        $app = $this->option('app');
        $manager = is_string($app) && $app !== '' ? $manager->app($app) : $manager;
        $token = $this->argument('token');

        if (! is_string($token) || $token === '') {
            $answer = $this->secret('Access token');
            $token = is_string($answer) ? $answer : '';
        }

        try {
            $info = $manager->debugger()->inspect($token);
        } catch (GraphException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['Valid', $info->isValid ? 'yes' : 'no'],
            ['Type', $info->type->value ?? '-'],
            ['App ID', $info->appId ?? '-'],
            ['User ID', $info->userId ?? '-'],
            ['Profile ID', $info->profileId ?? '-'],
            ['Expires', $info->expiresAt?->toDateTimeString() ?? 'never'],
            ['Data access expires', $info->dataAccessExpiresAt?->toDateTimeString() ?? '-'],
            ['Scopes', implode(', ', $info->scopes)],
            ['Error', $info->error ?? '-'],
        ]);

        return $info->isValid ? self::SUCCESS : self::FAILURE;
    }
}
