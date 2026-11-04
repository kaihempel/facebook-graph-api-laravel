<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Data\PageConnection;
use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\MetaGraphServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public const string APP_ID = '1234567890';

    public const string APP_SECRET = 'test-app-secret';

    public const string VERIFY_TOKEN = 'verify-me';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function getPackageProviders($app): array
    {
        return [MetaGraphServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['MetaGraph' => MetaGraph::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('meta-graph.apps', [
            'main' => [
                'app_id' => self::APP_ID,
                'app_secret' => self::APP_SECRET,
                'redirect' => 'https://example.test/meta/callback',
                'scopes' => ['pages_show_list', 'pages_manage_posts'],
            ],
            'kunde-b' => [
                'app_id' => '999',
                'app_secret' => 'secret-b',
                'redirect' => 'https://b.example.test/callback',
                'scopes' => ['pages_show_list'],
            ],
        ]);
        $app['config']->set('meta-graph.webhooks.verify_token', self::VERIFY_TOKEN);
        $app['config']->set('meta-graph.retry.base_ms', 1);
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['create_meta_graph_tokens_table', 'create_meta_graph_uploads_table'] as $migration) {
            (include __DIR__.'/../database/migrations/'.$migration.'.php.stub')->up();
        }
    }

    protected function page(string $id = '111', string $token = 'page-token-111', array $tasks = ['CREATE_CONTENT', 'MANAGE']): PageConnection
    {
        return new PageConnection($id, 'Testseite', AccessToken::page($token), 'Local business', $tasks);
    }
}
