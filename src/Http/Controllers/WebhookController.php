<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Http\Controllers;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kuweh\MetaGraph\Exceptions\ConfigurationException;
use Kuweh\MetaGraph\GraphManager;
use Kuweh\MetaGraph\Jobs\ProcessWebhookPayload;
use Kuweh\MetaGraph\Webhooks\SignatureVerifier;

final class WebhookController
{
    public function __construct(
        private readonly GraphManager $manager,
        private readonly Config $config,
        private readonly BusDispatcher $bus,
    ) {}

    /**
     * Verifizierungs-Handshake: hub.challenge als Plain Text bei passendem Verify Token.
     */
    public function verify(Request $request): Response
    {
        // PHP wandelt "hub.mode" in "hub_mode" um; beide Varianten werden akzeptiert.
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        $expected = $this->config->get('meta-graph.webhooks.verify_token');

        if ($mode === 'subscribe'
            && is_string($token) && is_string($expected) && $expected !== ''
            && hash_equals($expected, $token)
            && is_string($challenge)) {
            return new Response($challenge, 200, ['Content-Type' => 'text/plain']);
        }

        return new Response('Forbidden', 403, ['Content-Type' => 'text/plain']);
    }

    /**
     * Prüft die Signatur auf den Rohbytes, antwortet sofort mit 200 und verarbeitet per Job.
     */
    public function handle(Request $request, ?string $app = null): Response
    {
        $manager = $app === null ? $this->manager : $this->manager->app($app);

        try {
            $secret = $manager->appConfig()->appSecret;
        } catch (ConfigurationException) {
            return new Response('Not Found', 404);
        }

        $raw = $request->getContent();

        if (! SignatureVerifier::verify($raw, $request->header(SignatureVerifier::HEADER), $secret)) {
            return new Response('Invalid signature', 403, ['Content-Type' => 'text/plain']);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return new Response('Invalid payload', 400, ['Content-Type' => 'text/plain']);
        }

        $job = new ProcessWebhookPayload($payload, $manager->appName());
        $connection = $this->config->get('meta-graph.webhooks.connection');
        $queue = $this->config->get('meta-graph.webhooks.queue');

        if (is_string($connection)) {
            $job->onConnection($connection);
        }

        if (is_string($queue)) {
            $job->onQueue($queue);
        }

        $this->bus->dispatch($job);

        return new Response('EVENT_RECEIVED', 200, ['Content-Type' => 'text/plain']);
    }
}
