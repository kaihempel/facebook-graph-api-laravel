<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Testing;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;

/**
 * Ein von GraphFake aufgezeichneter Request mit dekodierten Parametern.
 */
final readonly class RecordedRequest
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $files  Feldname => Dateiname
     */
    public function __construct(
        public string $method,
        public string $host,
        public string $path,
        public array $params,
        public array $files,
        public Request $request,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $url = $request->url();
        $parts = parse_url($url);
        $query = [];
        parse_str($parts['query'] ?? '', $query);

        $params = [];
        $files = [];

        if ($request->isMultipart()) {
            foreach ($request->data() as $part) {
                if (! is_array($part) || ! isset($part['name'])) {
                    continue;
                }

                $name = is_scalar($part['name']) ? (string) $part['name'] : '';

                if (isset($part['filename'])) {
                    $files[$name] = is_scalar($part['filename']) ? (string) $part['filename'] : $name;
                } else {
                    $params[$name] = $part['contents'] ?? null;
                }
            }
        } elseif ($request->method() !== 'GET' && $request->method() !== 'DELETE') {
            $params = $request->isForm() ? $request->data() : [];
        }

        /** @var array<string, mixed> $merged */
        $merged = array_merge($query, $params);

        return new self(
            method: strtoupper($request->method()),
            host: $parts['host'] ?? '',
            path: trim($parts['path'] ?? '', '/'),
            params: $merged,
            files: $files,
            request: $request,
        );
    }

    public function matches(string $pattern): bool
    {
        $pattern = trim($pattern, '/');

        return Str::is($pattern, $this->path)
            || Str::is($pattern, $this->host.'/'.$this->path);
    }

    public function isBatch(): bool
    {
        return $this->method === 'POST' && isset($this->params['batch']) && preg_match('#^v\d+\.\d+$#', $this->path) === 1;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function batchOperations(): array
    {
        $batch = $this->params['batch'] ?? null;
        $decoded = is_string($batch) ? json_decode($batch, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, is_array(...))) : [];
    }

    public function header(string $name): ?string
    {
        $values = $this->request->header($name);
        $value = $values[0] ?? null;

        return is_string($value) ? $value : null;
    }
}
