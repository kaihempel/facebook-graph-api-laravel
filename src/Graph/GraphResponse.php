<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Graph;

use Illuminate\Support\Arr;
use Kuweh\MetaGraph\Data\RateLimitUsage;

final readonly class GraphResponse
{
    /** @var array<array-key, mixed> */
    private array $decoded;

    /**
     * @param  array<string, list<string>|string>  $headers
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
        public ?GraphRequest $request = null,
    ) {
        $decoded = json_decode($body, true);
        $this->decoded = is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, list<string>|string>  $headers
     */
    public static function fromArray(array $data, int $status = 200, array $headers = []): self
    {
        return new self($status, (string) json_encode($data), $headers);
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->decoded : Arr::get($this->decoded, $key, $default);
    }

    /**
     * Rohdaten der Antwort.
     *
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->decoded;
    }

    /**
     * Items einer Edge-Antwort ("data").
     *
     * @return list<array<array-key, mixed>>
     */
    public function data(): array
    {
        $data = $this->decoded['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, is_array(...))) : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function paging(): array
    {
        $paging = $this->decoded['paging'] ?? [];

        return is_array($paging) ? $paging : [];
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300 && ! $this->hasError();
    }

    public function hasError(): bool
    {
        return isset($this->decoded['error']) && is_array($this->decoded['error']);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                $value = is_array($value) ? ($value[0] ?? null) : $value;

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    public function usage(): RateLimitUsage
    {
        return RateLimitUsage::fromHeaders($this->headers);
    }

    public function fbtraceId(): ?string
    {
        $trace = $this->json('error.fbtrace_id');

        return is_string($trace) ? $trace : $this->header('x-fb-trace-id');
    }

    /**
     * Gibt "id" der Antwort zurück (z. B. nach Create).
     */
    public function id(): ?string
    {
        $id = $this->decoded['id'] ?? null;

        return is_scalar($id) ? (string) $id : null;
    }
}
