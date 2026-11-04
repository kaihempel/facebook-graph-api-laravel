<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Auth;

use KaiHempel\MetaGraph\Data\TokenInfo;
use KaiHempel\MetaGraph\Graph\GraphClient;

/**
 * GET /debug_token mit App Token.
 */
final readonly class TokenDebugger
{
    public function __construct(
        private GraphClient $client,
    ) {}

    public function inspect(AccessToken|string $token): TokenInfo
    {
        $value = $token instanceof AccessToken ? $token->value : $token;

        $response = $this->client->send(
            $this->client->request('GET', 'debug_token', ['input_token' => $value])
                ->withToken($this->client->app()->appToken()),
        );

        $data = $response->json('data');

        return TokenInfo::fromArray(is_array($data) ? $data : []);
    }
}
