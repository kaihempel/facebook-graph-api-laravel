<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Graph\Batch;

use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Contracts\MediaSource;
use KaiHempel\MetaGraph\Graph\FieldSet;

/**
 * Eine Operation innerhalb eines Batch Requests (fluent konfigurierbar).
 */
final class BatchOperation
{
    private ?string $name = null;

    private ?string $dependsOn = null;

    private ?bool $omitResponseOnSuccess = null;

    private ?AccessToken $token = null;

    /** @var array<string, MediaSource> */
    private array $files = [];

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $method,
        public readonly string $relativeUrl,
        private array $params = [],
    ) {}

    /**
     * Name für JSONPath-Referenzen wie {result=name:$.id}.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function dependsOn(string $name): self
    {
        $this->dependsOn = $name;

        return $this;
    }

    public function omitResponseOnSuccess(bool $omit = true): self
    {
        $this->omitResponseOnSuccess = $omit;

        return $this;
    }

    /**
     * Eigener Token für diese Operation (z. B. Page Token einer anderen Seite).
     */
    public function withToken(AccessToken $token): self
    {
        $this->token = $token;

        return $this;
    }

    /**
     * @param  FieldSet|list<string>|string  $fields
     */
    public function fields(FieldSet|array|string $fields): self
    {
        $this->params['fields'] = FieldSet::from($fields)->toString();

        return $this;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function with(array $params): self
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    public function attach(string $name, MediaSource $file): self
    {
        $this->files[$name] = $file;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getDependsOn(): ?string
    {
        return $this->dependsOn;
    }

    public function getToken(): ?AccessToken
    {
        return $this->token;
    }

    public function getOmitResponseOnSuccess(): ?bool
    {
        return $this->omitResponseOnSuccess;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return array<string, MediaSource>
     */
    public function getFiles(): array
    {
        return $this->files;
    }
}
