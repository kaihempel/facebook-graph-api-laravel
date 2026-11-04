<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph;

use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Exceptions\ConfigurationException;

/**
 * Konfiguration einer Meta-App aus "meta-graph.apps".
 */
final readonly class AppConfig
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $name,
        public string $appId,
        public string $appSecret,
        public ?string $redirect = null,
        public array $scopes = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $appId = $config['app_id'] ?? null;
        $appSecret = $config['app_secret'] ?? null;

        if (! is_scalar($appId) || (string) $appId === '' || ! is_scalar($appSecret) || (string) $appSecret === '') {
            throw new ConfigurationException("Meta app [{$name}] requires app_id and app_secret.");
        }

        $redirect = $config['redirect'] ?? null;
        $scopes = $config['scopes'] ?? [];

        return new self(
            name: $name,
            appId: (string) $appId,
            appSecret: (string) $appSecret,
            redirect: is_string($redirect) && $redirect !== '' ? $redirect : null,
            scopes: is_array($scopes) ? array_values(array_map(strval(...), array_filter($scopes, is_string(...)))) : [],
        );
    }

    public function appToken(): AccessToken
    {
        return AccessToken::app($this->appId, $this->appSecret);
    }
}
