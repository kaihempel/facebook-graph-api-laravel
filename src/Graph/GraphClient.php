<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Graph;

use Closure;
use Illuminate\Container\Container;
use KaiHempel\MetaGraph\AppConfig;
use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Contracts\UploadSessionStore;
use KaiHempel\MetaGraph\Exceptions\BatchTooLargeException;
use KaiHempel\MetaGraph\Graph\Batch\BatchItemResult;
use KaiHempel\MetaGraph\Graph\Batch\BatchRequest;
use KaiHempel\MetaGraph\Graph\Batch\BatchResponse;
use KaiHempel\MetaGraph\Http\Transport;
use KaiHempel\MetaGraph\Resources\Comments\CommentResource;
use KaiHempel\MetaGraph\Resources\Insights\InsightResource;
use KaiHempel\MetaGraph\Resources\Pages\PageResource;
use KaiHempel\MetaGraph\Resources\Photos\PhotoResource;
use KaiHempel\MetaGraph\Resources\Posts\PostResource;
use KaiHempel\MetaGraph\Resources\Reels\ReelResource;
use KaiHempel\MetaGraph\Resources\Videos\VideoResource;
use KaiHempel\MetaGraph\Support\ClientOptions;
use KaiHempel\MetaGraph\Uploads\ArrayUploadSessionStore;

/**
 * Generischer Low-Level-Client: erreicht jeden Graph-Endpunkt und kapselt
 * Version, Token, appsecret_proof, Fields, Pagination und Batch.
 */
final readonly class GraphClient
{
    public function __construct(
        private Transport $transport,
        private ?AccessToken $token = null,
        private ClientOptions $options = new ClientOptions,
        private ?string $pageId = null,
        private bool $appsecretProof = true,
    ) {}

    public function app(): AppConfig
    {
        return $this->transport->app();
    }

    public function token(): ?AccessToken
    {
        return $this->token;
    }

    public function options(): ClientOptions
    {
        return $this->options;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function version(): string
    {
        return $this->options->version;
    }

    /**
     * ID der Seite im Kontext asPage(); sonst "me" (wird bei Page Tokens auf die Seite aufgelöst).
     */
    public function pageId(): string
    {
        return $this->pageId ?? 'me';
    }

    public function withToken(?AccessToken $token, ?string $pageId = null): self
    {
        return new self($this->transport, $token, $this->options, $pageId, $this->appsecretProof);
    }

    /**
     * Client mit anderer Graph-API-Version.
     */
    public function usingVersion(string $version): self
    {
        return new self($this->transport, $this->token, $this->options->withVersion($version), $this->pageId, $this->appsecretProof);
    }

    /**
     * Neuer Request mit Token und Version dieses Clients.
     *
     * @param  array<string, mixed>  $params
     */
    public function request(string $method, string $path = '', array $params = []): GraphRequest
    {
        return new GraphRequest(
            method: $method,
            path: $path,
            params: $params,
            token: $this->token,
            version: $this->options->version,
        );
    }

    public function node(string $id): NodeQuery
    {
        return new NodeQuery($this, $this->request('GET', $id));
    }

    public function me(): NodeQuery
    {
        return $this->node('me');
    }

    /**
     * Mehrere Nodes in einem Request (?ids=). Meta zählt jede ID als eigenen Call.
     *
     * @param  list<string>  $ids
     */
    public function nodes(array $ids): NodeQuery
    {
        return new NodeQuery($this, $this->request('GET', '', ['ids' => implode(',', $ids)]));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function get(string $path, array $params = []): GraphResponse
    {
        return $this->send($this->request('GET', $path, $params));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function post(string $path, array $params = []): GraphResponse
    {
        return $this->send($this->request('POST', $path, $params));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(string $path, array $params = []): GraphResponse
    {
        return $this->send($this->request('DELETE', $path, $params));
    }

    public function send(GraphRequest $request): GraphResponse
    {
        return $this->transport->send($request);
    }

    public function paginator(): Paginator
    {
        return new Paginator($this, $this->options->maxPages);
    }

    /**
     * Batch Request. Mehr als 50 Operationen werden automatisch in Chunks geteilt.
     * Abhängigkeiten über Chunk-Grenzen hinweg werden von Meta nicht aufgelöst.
     *
     * @param  BatchRequest|Closure(BatchRequest): mixed  $batch
     */
    public function batch(BatchRequest|Closure $batch): BatchResponse
    {
        $batch = $this->resolveBatch($batch);
        $items = [];
        $offset = 0;

        foreach ($batch->chunks() as $chunk) {
            foreach ($this->sendBatchChunk($chunk, $offset) as $item) {
                $items[] = $item;
            }

            $offset += $chunk->count();
        }

        return new BatchResponse($items, $this);
    }

    /**
     * Wie batch(), wirft aber BatchTooLargeException bei mehr als 50 Operationen.
     *
     * @param  BatchRequest|Closure(BatchRequest): mixed  $batch
     */
    public function batchStrict(BatchRequest|Closure $batch): BatchResponse
    {
        $batch = $this->resolveBatch($batch);

        if ($batch->count() > BatchRequest::MAX_OPERATIONS) {
            throw BatchTooLargeException::make($batch->count(), BatchRequest::MAX_OPERATIONS);
        }

        return $this->batch($batch);
    }

    public function pages(): PageResource
    {
        return new PageResource($this);
    }

    public function posts(): PostResource
    {
        return new PostResource($this);
    }

    public function photos(): PhotoResource
    {
        return new PhotoResource($this);
    }

    public function videos(): VideoResource
    {
        return new VideoResource($this, $this->uploadSessions());
    }

    public function reels(): ReelResource
    {
        return new ReelResource($this);
    }

    public function comments(): CommentResource
    {
        return new CommentResource($this);
    }

    public function insights(): InsightResource
    {
        return new InsightResource($this);
    }

    public function uploadSessions(): UploadSessionStore
    {
        $container = Container::getInstance();

        return $container->bound(UploadSessionStore::class)
            ? $container->make(UploadSessionStore::class)
            : new ArrayUploadSessionStore;
    }

    /**
     * @param  BatchRequest|Closure(BatchRequest): mixed  $batch
     */
    private function resolveBatch(BatchRequest|Closure $batch): BatchRequest
    {
        if ($batch instanceof Closure) {
            $request = new BatchRequest;
            $batch($request);

            return $request;
        }

        return $batch;
    }

    /**
     * @return list<BatchItemResult>
     */
    private function sendBatchChunk(BatchRequest $chunk, int $offset): array
    {
        [$payload, $files] = $chunk->toPayload($this->appsecretProof ? $this->app()->appSecret : null);

        // Ein Top-Level-Token ist Pflicht, auch wenn alle Operationen eigene Tokens haben.
        $request = $this->request('POST', '', [
            'batch' => $payload,
            'include_headers' => false,
        ])->withToken($this->token ?? $this->app()->appToken());

        foreach ($files as $name => $file) {
            $request = $request->attach($name, $file);
        }

        $raw = $this->send($request)->raw();
        $results = [];

        foreach ($chunk->operations() as $index => $operation) {
            $item = $raw[$index] ?? null;
            $results[] = BatchItemResult::fromResponseItem($offset + $index, $operation, is_array($item) ? $item : null);
        }

        return $results;
    }
}
