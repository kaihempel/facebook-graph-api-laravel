<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Graph;

use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Contracts\MediaSource;
use KaiHempel\MetaGraph\Support\Redactor;
use Psr\Http\Message\StreamInterface;

/**
 * Unveränderliches Request-Modell. Jede with*-Methode liefert eine neue Instanz.
 */
final readonly class GraphRequest
{
    public const string DEFAULT_HOST = 'graph.facebook.com';

    public const string VIDEO_HOST = 'graph-video.facebook.com';

    public const string RUPLOAD_HOST = 'rupload.facebook.com';

    public string $method;

    public string $path;

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, MediaSource>  $files
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $method = 'GET',
        string $path = '',
        public array $params = [],
        public array $files = [],
        public ?AccessToken $token = null,
        public string $version = 'v26.0',
        public string $host = self::DEFAULT_HOST,
        public array $headers = [],
        public StreamInterface|string|null $body = null,
        public bool $versioned = true,
        public bool $retryable = true,
        public bool $tokenInHeader = false,
        public ?int $timeout = null,
    ) {
        $this->method = strtoupper($method);
        $this->path = trim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function get(string $path, array $params = []): self
    {
        return new self('GET', $path, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function post(string $path, array $params = []): self
    {
        return new self('POST', $path, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function delete(string $path, array $params = []): self
    {
        return new self('DELETE', $path, $params);
    }

    public function withMethod(string $method): self
    {
        return $this->copy(['method' => $method]);
    }

    public function withPath(string $path): self
    {
        return $this->copy(['path' => $path]);
    }

    public function edge(string $edge): self
    {
        return $this->copy(['path' => $this->path === '' ? $edge : $this->path.'/'.trim($edge, '/')]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function withParams(array $params): self
    {
        return $this->copy(['params' => array_merge($this->params, $params)]);
    }

    public function withParam(string $key, mixed $value): self
    {
        return $this->withParams([$key => $value]);
    }

    public function withoutParam(string ...$keys): self
    {
        return $this->copy(['params' => array_diff_key($this->params, array_flip($keys))]);
    }

    /**
     * @param  FieldSet|list<string>|string  $fields
     */
    public function fields(FieldSet|array|string $fields): self
    {
        $set = FieldSet::from($fields);

        return $set->isEmpty() ? $this->withoutParam('fields') : $this->withParam('fields', $set->toString());
    }

    public function attach(string $name, MediaSource $file): self
    {
        return $this->copy(['files' => [...$this->files, $name => $file]]);
    }

    public function withToken(?AccessToken $token): self
    {
        return $this->copy(['token' => $token]);
    }

    public function version(string $version): self
    {
        return $this->copy(['version' => $version]);
    }

    public function host(string $host): self
    {
        return $this->copy(['host' => $host]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return $this->copy(['headers' => array_merge($this->headers, $headers)]);
    }

    public function withBody(StreamInterface|string|null $body): self
    {
        return $this->copy(['body' => $body]);
    }

    public function unversioned(bool $unversioned = true): self
    {
        return $this->copy(['versioned' => ! $unversioned]);
    }

    public function retryable(bool $retryable = true): self
    {
        return $this->copy(['retryable' => $retryable]);
    }

    /**
     * Sendet den Token als "Authorization: OAuth {token}" statt als Parameter
     * (Resumable Upload API und rupload.facebook.com).
     */
    public function tokenInHeader(bool $inHeader = true): self
    {
        return $this->copy(['tokenInHeader' => $inHeader]);
    }

    public function timeout(?int $seconds): self
    {
        return $this->copy(['timeout' => $seconds]);
    }

    /**
     * Übernimmt Host und Pfad aus einer absoluten URL (z. B. upload_url von Reels).
     */
    public function withUrl(string $url): self
    {
        $parts = parse_url($url);
        $query = [];
        parse_str($parts['query'] ?? '', $query);

        /** @var array<string, mixed> $query */
        return $this->copy([
            'host' => $parts['host'] ?? $this->host,
            'path' => $parts['path'] ?? '',
            'versioned' => false,
            'params' => array_merge($query, $this->params),
        ]);
    }

    public function url(): string
    {
        $segments = array_filter([
            $this->versioned ? $this->version : null,
            $this->path,
        ], static fn (?string $s): bool => $s !== null && $s !== '');

        return 'https://'.$this->host.'/'.implode('/', $segments);
    }

    public function hasFiles(): bool
    {
        return $this->files !== [];
    }

    /**
     * Darstellung ohne Token und appsecret_proof für Logs und Exceptions.
     *
     * @return array{method: string, url: string, params: array<array-key, mixed>, token_type: string|null}
     */
    public function toSafeArray(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url(),
            'params' => Redactor::redact($this->params),
            'token_type' => $this->token?->type->value,
        ];
    }

    /**
     * @param  array{
     *     method?: string,
     *     path?: string,
     *     params?: array<string, mixed>,
     *     files?: array<string, MediaSource>,
     *     token?: AccessToken|null,
     *     version?: string,
     *     host?: string,
     *     headers?: array<string, string>,
     *     body?: StreamInterface|string|null,
     *     versioned?: bool,
     *     retryable?: bool,
     *     tokenInHeader?: bool,
     *     timeout?: int|null,
     * }  $changes
     */
    private function copy(array $changes): self
    {
        return new self(
            method: $changes['method'] ?? $this->method,
            path: $changes['path'] ?? $this->path,
            params: $changes['params'] ?? $this->params,
            files: $changes['files'] ?? $this->files,
            token: array_key_exists('token', $changes) ? $changes['token'] : $this->token,
            version: $changes['version'] ?? $this->version,
            host: $changes['host'] ?? $this->host,
            headers: $changes['headers'] ?? $this->headers,
            body: array_key_exists('body', $changes) ? $changes['body'] : $this->body,
            versioned: $changes['versioned'] ?? $this->versioned,
            retryable: $changes['retryable'] ?? $this->retryable,
            tokenInHeader: $changes['tokenInHeader'] ?? $this->tokenInHeader,
            timeout: array_key_exists('timeout', $changes) ? $changes['timeout'] : $this->timeout,
        );
    }
}
