<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Resources;

use KaiHempel\MetaGraph\Graph\GraphClient;
use KaiHempel\MetaGraph\Graph\GraphResponse;
use KaiHempel\MetaGraph\Support\Values;

/**
 * Basis aller Ressourcen. Ressourcen bauen ausschließlich auf dem GraphClient auf.
 */
abstract class Resource
{
    public function __construct(
        protected readonly GraphClient $client,
    ) {}

    public function client(): GraphClient
    {
        return $this->client;
    }

    protected function succeeded(GraphResponse $response): bool
    {
        return Values::bool($response->raw(), 'success', true) ?? true;
    }
}
