<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Console;

use Illuminate\Console\Command;
use Kuweh\MetaGraph\Exceptions\GraphException;
use Kuweh\MetaGraph\GraphManager;

/**
 * Konfiguriert App-weite Webhook-Felder über /{app-id}/subscriptions.
 */
final class SubscribeWebhooksCommand extends Command
{
    protected $signature = 'meta-graph:webhooks:subscribe
        {--object=page : Objekttyp (page, user, ...)}
        {--fields=feed,mention : Kommagetrennte Felder}
        {--callback= : Callback-URL (Standard: Paket-Route)}
        {--app= : App aus der Config}';

    protected $description = 'Abonniert App-weite Webhook-Felder bei Meta.';

    public function handle(GraphManager $manager): int
    {
        $app = $this->option('app');
        $manager = is_string($app) && $app !== '' ? $manager->app($app) : $manager;
        $config = $manager->config();
        $webhooks = is_array($config['webhooks'] ?? null) ? $config['webhooks'] : [];
        $verifyToken = $webhooks['verify_token'] ?? null;

        if (! is_string($verifyToken) || $verifyToken === '') {
            $this->error('meta-graph.webhooks.verify_token is not configured.');

            return self::FAILURE;
        }

        $callback = $this->option('callback');
        if (! is_string($callback) || $callback === '') {
            $path = is_string($webhooks['path'] ?? null) ? $webhooks['path'] : 'meta-graph/webhook';
            $callback = url($path);
        }

        $object = is_string($this->option('object')) ? $this->option('object') : 'page';
        $fields = is_string($this->option('fields')) ? $this->option('fields') : 'feed';

        try {
            $manager->asApp()->post($manager->appConfig()->appId.'/subscriptions', [
                'object' => $object,
                'callback_url' => $callback,
                'fields' => $fields,
                'verify_token' => $verifyToken,
                'include_values' => true,
            ]);
        } catch (GraphException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Subscribed [{$object}] fields [{$fields}] to {$callback}.");

        return self::SUCCESS;
    }
}
