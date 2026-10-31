<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Http;

use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Kuweh\MetaGraph\AppConfig;
use Kuweh\MetaGraph\Auth\AppSecretProof;
use Kuweh\MetaGraph\Events\TokenInvalidated;
use Kuweh\MetaGraph\Events\UserActionRequired;
use Kuweh\MetaGraph\Exceptions\AuthenticationException;
use Kuweh\MetaGraph\Exceptions\ErrorMapper;
use Kuweh\MetaGraph\Exceptions\GraphException;
use Kuweh\MetaGraph\Exceptions\RateLimitException;
use Kuweh\MetaGraph\Exceptions\TransientException;
use Kuweh\MetaGraph\Exceptions\UserActionRequiredException;
use Kuweh\MetaGraph\Graph\GraphRequest;
use Kuweh\MetaGraph\Graph\GraphResponse;
use Kuweh\MetaGraph\Graph\ParameterSerializer;
use Kuweh\MetaGraph\Support\Dispatch;
use Kuweh\MetaGraph\Support\Redactor;
use Psr\Log\LoggerInterface;

/**
 * HTTP-Schicht auf Basis des Laravel HTTP Clients (Http::fake() funktioniert nativ).
 */
final readonly class Transport
{
    public function __construct(
        private AppConfig $app,
        private RetryPolicy $retry,
        private RateLimitTracker $tracker,
        private bool $appsecretProof = true,
        private int $timeout = 30,
        private int $connectTimeout = 10,
        private ?LoggerInterface $logger = null,
    ) {}

    public function app(): AppConfig
    {
        return $this->app;
    }

    public function tracker(): RateLimitTracker
    {
        return $this->tracker;
    }

    public function retryPolicy(): RetryPolicy
    {
        return $this->retry;
    }

    /**
     * Sendet den Request inklusive Retry-Logik und übersetzt Fehler in Exceptions.
     *
     * @throws GraphException
     */
    public function send(GraphRequest $request): GraphResponse
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->dispatch($request);
            } catch (ConnectionException $e) {
                if ($request->retryable && $this->retry->shouldRetry($attempt)) {
                    $this->retry->sleep($attempt);

                    continue;
                }

                throw new TransientException(
                    message: 'Connection to the Graph API failed: '.Redactor::url($e->getMessage()),
                    type: 'ConnectionException',
                    request: $request,
                    previous: $e,
                );
            }

            $this->tracker->record($this->app, $response->usage());

            if ($response->successful()) {
                return $response;
            }

            $exception = ErrorMapper::fromResponse($response, $request);

            if ($request->retryable && $this->retry->shouldRetry($attempt, $exception)) {
                $this->retry->sleep($attempt);

                continue;
            }

            $this->report($exception, $request);

            throw $exception;
        }
    }

    /**
     * Ein einzelner HTTP-Versuch ohne Retry und ohne Fehler-Mapping.
     *
     * @throws ConnectionException
     */
    public function dispatch(GraphRequest $request): GraphResponse
    {
        $started = microtime(true);
        [$params, $headers] = $this->authenticate($request);
        $pending = $this->pending($request, $headers);
        $params = ParameterSerializer::serialize($params);
        $url = $request->url();

        $response = match (true) {
            $request->method === 'GET' => $pending->withQueryParameters($params)->get($url),
            $request->method === 'DELETE' => $pending->withQueryParameters($params)->delete($url),
            $request->body !== null => $pending
                ->withQueryParameters($params)
                ->withBody($request->body, $request->headers['Content-Type'] ?? 'application/octet-stream')
                ->post($url),
            $request->hasFiles() => $this->attachFiles($pending, $request)->post($url, $params),
            default => $pending->asForm()->post($url, $params),
        };

        $result = $this->toGraphResponse($response, $request);
        $this->log($request, $result, $started);

        return $result;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function authenticate(GraphRequest $request): array
    {
        $params = $request->params;
        $headers = $request->headers;
        $token = $request->token;

        if ($token === null) {
            return [$params, $headers];
        }

        if ($request->tokenInHeader) {
            $headers['Authorization'] = 'OAuth '.$token->value;
        } else {
            $params['access_token'] = $token->value;
        }

        if ($this->appsecretProof && $request->host !== GraphRequest::RUPLOAD_HOST) {
            $params['appsecret_proof'] = AppSecretProof::make($token, $this->app->appSecret);
        }

        return [$params, $headers];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function pending(GraphRequest $request, array $headers): PendingRequest
    {
        unset($headers['Content-Type']);

        /** @var HttpFactory $factory */
        $factory = Container::getInstance()->make(HttpFactory::class);

        return $factory
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout($request->timeout ?? $this->timeout)
            ->connectTimeout($this->connectTimeout);
    }

    private function attachFiles(PendingRequest $pending, GraphRequest $request): PendingRequest
    {
        foreach ($request->files as $name => $file) {
            $pending = $pending->attach($name, $file->openStream(), $file->fileName(), ['Content-Type' => $file->mimeType()]);
        }

        return $pending;
    }

    private function toGraphResponse(Response $response, GraphRequest $request): GraphResponse
    {
        /** @var array<string, list<string>> $headers */
        $headers = $response->headers();

        return new GraphResponse($response->status(), $response->body(), $headers, $request);
    }

    private function report(GraphException $exception, GraphRequest $request): void
    {
        if ($exception instanceof RateLimitException) {
            $this->tracker->hit($this->app, $exception);
        } elseif ($exception instanceof UserActionRequiredException) {
            Dispatch::event(new UserActionRequired($request->token, $this->app->name, $exception->hint(), $exception));
        } elseif ($exception instanceof AuthenticationException) {
            Dispatch::event(new TokenInvalidated($request->token, $this->app->name, $exception));
        }

        $this->logger?->warning('Graph API error: '.$exception->getMessage(), $exception->context());
    }

    private function log(GraphRequest $request, GraphResponse $response, float $started): void
    {
        $this->logger?->info('Graph API request', [
            'method' => $request->method,
            'path' => $request->url(),
            'status' => $response->status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'fbtrace_id' => $response->fbtraceId(),
            'usage' => $response->usage()->toArray(),
        ]);
    }
}
