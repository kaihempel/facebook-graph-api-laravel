<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Console;

use Illuminate\Console\Command;
use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Exceptions\GraphException;
use KaiHempel\MetaGraph\GraphManager;

final class ListPagesCommand extends Command
{
    protected $signature = 'meta-graph:pages
        {--user= : Gespeicherte User-ID (TokenRepository) oder User Token}
        {--connect : Page Tokens im TokenRepository speichern}
        {--app= : App aus der Config}';

    protected $description = 'Listet die Seiten eines Users inklusive Tasks.';

    public function handle(GraphManager $manager): int
    {
        $app = $this->option('app');
        $manager = is_string($app) && $app !== '' ? $manager->app($app) : $manager;
        $user = $this->option('user');

        if (! is_string($user) || $user === '') {
            $answer = $this->secret('User ID or user access token');
            $user = is_string($answer) ? $answer : '';
        }

        $token = $manager->tokenRepository()->find(GraphManager::OWNER_USER, $user) ?? AccessToken::user($user);

        try {
            $pages = $manager->tokens()->pageTokens($token);
        } catch (GraphException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('connect')) {
            $pages->each(fn ($page) => $manager->connectPage($page));
        }

        $this->table(
            ['ID', 'Name', 'Category', 'Tasks'],
            $pages->map(fn ($page): array => [$page->id, $page->name, $page->category ?? '-', implode(', ', $page->tasks)])->all(),
        );

        return self::SUCCESS;
    }
}
