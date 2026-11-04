<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Graph\Batch;

use Illuminate\Support\Arr;
use KaiHempel\MetaGraph\Exceptions\ErrorMapper;
use KaiHempel\MetaGraph\Exceptions\GraphException;
use KaiHempel\MetaGraph\Graph\GraphResponse;

/**
 * Ergebnis einer einzelnen Batch-Operation. Der body-String wird dekodiert.
 */
final readonly class BatchItemResult
{
    /**
     * @param  array<array-key, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $index,
        public BatchOperation $operation,
        private ?int $status,
        private ?array $body = null,
        private array $headers = [],
        private bool $timedOut = false,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $item  Eintrag aus der Batch-Antwort (null bei Timeout)
     */
    public static function fromResponseItem(int $index, BatchOperation $operation, ?array $item): self
    {
        if ($item === null) {
            return self::timedOut($index, $operation);
        }

        $headers = [];
        foreach (is_array($item['headers'] ?? null) ? $item['headers'] : [] as $header) {
            if (is_array($header) && is_string($header['name'] ?? null) && is_scalar($header['value'] ?? null)) {
                $headers[$header['name']] = (string) $header['value'];
            }
        }

        $body = $item['body'] ?? null;
        $decoded = is_string($body) ? json_decode($body, true) : $body;
        $code = $item['code'] ?? null;

        return new self(
            index: $index,
            operation: $operation,
            status: is_numeric($code) ? (int) $code : null,
            body: is_array($decoded) ? $decoded : (is_scalar($decoded) ? ['result' => $decoded] : null),
            headers: $headers,
        );
    }

    public static function timedOut(int $index, BatchOperation $operation): self
    {
        return new self($index, $operation, null, null, [], true);
    }

    public function name(): ?string
    {
        return $this->operation->getName();
    }

    public function successful(): bool
    {
        return ! $this->timedOut
            && $this->status !== null
            && $this->status >= 200 && $this->status < 300
            && ! isset($this->body['error']);
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function isTimedOut(): bool
    {
        return $this->timedOut;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->body : Arr::get($this->body ?? [], $key, $default);
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function toResponse(): GraphResponse
    {
        return new GraphResponse($this->status ?? 0, (string) json_encode($this->body ?? []), $this->headers);
    }

    public function exception(): ?GraphException
    {
        if ($this->successful()) {
            return null;
        }

        if ($this->timedOut) {
            return ErrorMapper::fromError([
                'message' => 'Batch operation timed out.',
                'type' => 'BatchTimeout',
                'code' => 2,
            ]);
        }

        $error = $this->body['error'] ?? null;

        return is_array($error)
            ? ErrorMapper::fromError($error, $this->status ?? 0)
            : ErrorMapper::fromResponse($this->toResponse());
    }
}
