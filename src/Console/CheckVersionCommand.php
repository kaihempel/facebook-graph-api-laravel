<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Kuweh\MetaGraph\GraphManager;
use Kuweh\MetaGraph\Support\GraphVersions;

/**
 * Vergleicht die konfigurierte Version mit dem Ablaufdatum und warnt rechtzeitig.
 */
final class CheckVersionCommand extends Command
{
    protected $signature = 'meta-graph:version:check {--warn-days=90 : Vorwarnzeit in Tagen}';

    protected $description = 'Prüft, ob die konfigurierte Graph-API-Version bald abläuft.';

    public function handle(GraphManager $manager): int
    {
        $config = $manager->config();
        $version = is_string($config['version'] ?? null) ? $config['version'] : GraphVersions::LATEST;
        $overrides = is_array($config['version_expirations'] ?? null) ? $config['version_expirations'] : [];
        $warnDays = is_numeric($this->option('warn-days')) ? (int) $this->option('warn-days') : 90;

        if (! GraphVersions::isKnown($version, $overrides)) {
            $this->warn("Graph API version {$version} is unknown to this package. Please check the changelog.");

            return self::SUCCESS;
        }

        $expiresAt = GraphVersions::expiresAt($version, $overrides);

        if ($expiresAt === null) {
            $this->info("Graph API version {$version} has no published expiry date yet.");

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();

        if ($expiresAt->lessThanOrEqualTo($now)) {
            $this->error("Graph API version {$version} expired on {$expiresAt->toDateString()}. Meta falls back to the oldest available version.");

            return self::FAILURE;
        }

        $days = (int) $now->diffInDays($expiresAt);

        if ($days <= $warnDays) {
            $this->warn("Graph API version {$version} expires on {$expiresAt->toDateString()} ({$days} days). Upgrade to ".GraphVersions::LATEST.'.');

            return self::SUCCESS;
        }

        $this->info("Graph API version {$version} is supported until {$expiresAt->toDateString()} ({$days} days).");

        return self::SUCCESS;
    }
}
