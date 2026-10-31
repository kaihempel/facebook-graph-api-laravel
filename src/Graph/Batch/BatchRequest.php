<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Graph\Batch;

use Countable;
use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Auth\AppSecretProof;
use Kuweh\MetaGraph\Contracts\MediaSource;
use Kuweh\MetaGraph\Graph\ParameterSerializer;

/**
 * Sammelt Operationen für einen Batch Request.
 *
 * @see https://developers.facebook.com/docs/graph-api/batch-requests
 */
final class BatchRequest implements Countable
{
    public const int MAX_OPERATIONS = 50;

    /** @var list<BatchOperation> */
    private array $operations = [];

    /**
     * @param  list<BatchOperation>  $operations
     */
    public function __construct(array $operations = [])
    {
        foreach ($operations as $operation) {
            $this->add($operation);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function get(string $relativeUrl, array $params = []): BatchOperation
    {
        return $this->add(new BatchOperation('GET', $relativeUrl, $params));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function post(string $relativeUrl, array $params = []): BatchOperation
    {
        return $this->add(new BatchOperation('POST', $relativeUrl, $params));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(string $relativeUrl, array $params = []): BatchOperation
    {
        return $this->add(new BatchOperation('DELETE', $relativeUrl, $params));
    }

    public function add(BatchOperation $operation): BatchOperation
    {
        $this->operations[] = $operation;

        return $operation;
    }

    /**
     * @return list<BatchOperation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function count(): int
    {
        return count($this->operations);
    }

    /**
     * Teilt den Batch in Chunks von höchstens 50 Operationen.
     *
     * @return list<self>
     */
    public function chunks(int $size = self::MAX_OPERATIONS): array
    {
        return array_map(
            static fn (array $chunk): self => new self($chunk),
            array_chunk($this->operations, max(1, $size)),
        );
    }

    /**
     * Baut das "batch"-Array und die Multipart-Dateien.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, MediaSource>}
     */
    public function toPayload(?string $appSecret = null): array
    {
        $payload = [];
        $files = [];

        foreach ($this->operations as $index => $operation) {
            $params = $operation->getParams();

            if (($token = $operation->getToken()) instanceof AccessToken) {
                $params['access_token'] = $token->value;

                if ($appSecret !== null) {
                    $params['appsecret_proof'] = AppSecretProof::make($token, $appSecret);
                }
            }

            $serialized = ParameterSerializer::serialize($params);
            $relativeUrl = ltrim($operation->relativeUrl, '/');
            $item = ['method' => $operation->method];

            if ($operation->method === 'POST') {
                $item['relative_url'] = $relativeUrl;

                if ($serialized !== []) {
                    $item['body'] = http_build_query($serialized, '', '&', PHP_QUERY_RFC3986);
                }
            } else {
                $item['relative_url'] = $serialized === []
                    ? $relativeUrl
                    : $relativeUrl.(str_contains($relativeUrl, '?') ? '&' : '?').http_build_query($serialized, '', '&', PHP_QUERY_RFC3986);
            }

            if ($operation->getName() !== null) {
                $item['name'] = $operation->getName();
            }

            if ($operation->getDependsOn() !== null) {
                $item['depends_on'] = $operation->getDependsOn();
            }

            if ($operation->getOmitResponseOnSuccess() !== null) {
                $item['omit_response_on_success'] = $operation->getOmitResponseOnSuccess();
            }

            $names = [];
            foreach ($operation->getFiles() as $name => $file) {
                $key = array_key_exists($name, $files) ? $name.'_'.$index : $name;
                $files[$key] = $file;
                $names[] = $key;
            }

            if ($names !== []) {
                $item['attached_files'] = implode(',', $names);
            }

            $payload[] = $item;
        }

        return [$payload, $files];
    }
}
