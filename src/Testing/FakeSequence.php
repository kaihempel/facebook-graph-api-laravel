<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Testing;

use OutOfBoundsException;

/**
 * Antwortfolge für wiederholte Requests auf denselben Endpunkt.
 */
final class FakeSequence
{
    /** @var list<FakeResponse> */
    private array $responses = [];

    private ?FakeResponse $whenEmpty = null;

    /**
     * @param  FakeResponse|array<array-key, mixed>  $response
     * @param  array<string, string>  $headers
     */
    public function push(FakeResponse|array $response, int $status = 200, array $headers = []): self
    {
        $this->responses[] = $response instanceof FakeResponse ? $response : new FakeResponse($response, $status, $headers);

        return $this;
    }

    public function pushError(int $code, string $message = 'Graph error', ?int $subcode = null, string $type = 'OAuthException', int $status = 400): self
    {
        return $this->push(GraphFake::error($code, $message, $subcode, $type, $status));
    }

    /**
     * @param  FakeResponse|array<array-key, mixed>  $response
     */
    public function whenEmpty(FakeResponse|array $response): self
    {
        $this->whenEmpty = $response instanceof FakeResponse ? $response : new FakeResponse($response);

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->responses === [];
    }

    public function next(): FakeResponse
    {
        $response = array_shift($this->responses);

        return $response ?? $this->whenEmpty ?? throw new OutOfBoundsException('GraphFake sequence is empty.');
    }
}
