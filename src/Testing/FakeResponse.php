<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;

/**
 * Gefakte Graph-Antwort inklusive optionaler Usage-Header.
 */
final readonly class FakeResponse
{
    /**
     * @param  array<array-key, mixed>|list<mixed>|null  $data
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public ?array $data,
        public int $status = 200,
        public array $headers = [],
    ) {}

    /**
     * @param  array<string, int>|null  $app  call_count, total_cputime, total_time
     * @param  array<string, list<array<string, int|string>>>  $businessUseCases  business-id => Einträge
     */
    public function withUsage(?array $app = null, array $businessUseCases = []): self
    {
        $headers = $this->headers;

        if ($app !== null) {
            $headers['X-App-Usage'] = (string) json_encode($app + ['call_count' => 0, 'total_cputime' => 0, 'total_time' => 0]);
        }

        if ($businessUseCases !== []) {
            $headers['X-Business-Use-Case-Usage'] = (string) json_encode($businessUseCases);
        }

        return new self($this->data, $this->status, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->data, $this->status, array_merge($this->headers, $headers));
    }

    public function toPromise(): PromiseInterface
    {
        return Factory::response(
            $this->data === null ? 'null' : (string) json_encode($this->data),
            $this->status,
            ['Content-Type' => 'application/json', ...$this->headers],
        );
    }

    /**
     * Darstellung als Element einer Batch-Antwort (body JSON-kodiert).
     *
     * @return array{code: int, headers: list<array{name: string, value: string}>, body: string}
     */
    public function toBatchItem(): array
    {
        $headers = [];
        foreach (['Content-Type' => 'application/json', ...$this->headers] as $name => $value) {
            $headers[] = ['name' => $name, 'value' => $value];
        }

        return [
            'code' => $this->status,
            'headers' => $headers,
            'body' => (string) json_encode($this->data),
        ];
    }
}
