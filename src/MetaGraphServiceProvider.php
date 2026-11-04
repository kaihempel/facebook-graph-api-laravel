<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use KaiHempel\MetaGraph\Auth\EloquentTokenRepository;
use KaiHempel\MetaGraph\Console\CheckTokensCommand;
use KaiHempel\MetaGraph\Console\CheckVersionCommand;
use KaiHempel\MetaGraph\Console\DebugTokenCommand;
use KaiHempel\MetaGraph\Console\ListPagesCommand;
use KaiHempel\MetaGraph\Console\SubscribeWebhooksCommand;
use KaiHempel\MetaGraph\Contracts\TokenRepository;
use KaiHempel\MetaGraph\Contracts\UploadSessionStore;
use KaiHempel\MetaGraph\Graph\GraphClient;
use KaiHempel\MetaGraph\Http\RateLimitTracker;
use KaiHempel\MetaGraph\Uploads\EloquentUploadSessionStore;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class MetaGraphServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('meta-graph')
            ->hasConfigFile()
            ->hasMigrations([
                'create_meta_graph_tokens_table',
                'create_meta_graph_uploads_table',
            ])
            ->hasCommands([
                CheckTokensCommand::class,
                DebugTokenCommand::class,
                ListPagesCommand::class,
                SubscribeWebhooksCommand::class,
                CheckVersionCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(RateLimitTracker::class, static function (Application $app): RateLimitTracker {
            return GraphManager::makeTracker($app->make(CacheFactory::class), self::config($app));
        });

        $this->app->bind(GraphManager::class, static function (Application $app): GraphManager {
            return new GraphManager($app);
        });

        $this->app->bind(GraphClient::class, static fn (Application $app): GraphClient => $app->make(GraphManager::class)->client());

        $this->app->singleton(TokenRepository::class, static function (Application $app): TokenRepository {
            $class = self::section($app, 'tokens')['repository'] ?? EloquentTokenRepository::class;

            /** @var TokenRepository */
            return $app->make(is_string($class) ? $class : EloquentTokenRepository::class);
        });

        $this->app->singleton(UploadSessionStore::class, static function (Application $app): UploadSessionStore {
            $class = self::section($app, 'uploads')['session_store'] ?? EloquentUploadSessionStore::class;

            /** @var UploadSessionStore */
            return $app->make(is_string($class) ? $class : EloquentUploadSessionStore::class);
        });
    }

    public function packageBooted(): void
    {
        if ((bool) $this->app->make(Config::class)->get('meta-graph.webhooks.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function section(Application $app, string $key): array
    {
        $section = self::config($app)[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function config(Application $app): array
    {
        $config = $app->make(Config::class)->get('meta-graph', []);

        return is_array($config) ? $config : [];
    }
}
