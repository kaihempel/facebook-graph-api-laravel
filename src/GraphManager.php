<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Log\LogManager;
use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Auth\OAuth\LoginFlow;
use Kuweh\MetaGraph\Auth\TokenDebugger;
use Kuweh\MetaGraph\Auth\TokenExchanger;
use Kuweh\MetaGraph\Contracts\TokenRepository;
use Kuweh\MetaGraph\Data\PageConnection;
use Kuweh\MetaGraph\Events\PageConnected;
use Kuweh\MetaGraph\Events\PageDisconnected;
use Kuweh\MetaGraph\Exceptions\ConfigurationException;
use Kuweh\MetaGraph\Graph\GraphClient;
use Kuweh\MetaGraph\Http\RateLimitTracker;
use Kuweh\MetaGraph\Http\RetryPolicy;
use Kuweh\MetaGraph\Http\Transport;
use Kuweh\MetaGraph\Support\ClientOptions;
use Kuweh\MetaGraph\Support\Dispatch;
use Kuweh\MetaGraph\Testing\GraphFake;
use Psr\Log\LoggerInterface;

/**
 * Einstiegspunkt: wählt die App (Multi-Tenant) und den Token-Kontext.
 *
 * @phpstan-consistent-constructor
 */
class GraphManager
{
    public const string OWNER_PAGE = 'page';

    public const string OWNER_USER = 'user';

    /** @var array<array-key, mixed> */
    protected array $config;

    protected bool $configResolved;

    /**
     * @param  array<array-key, mixed>|null  $config  Inhalt von config('meta-graph'); null = live aus der Config lesen
     */
    public function __construct(
        protected Container $container,
        ?array $config = null,
        protected ?string $appName = null,
    ) {
        $this->config = $config ?? [];
        $this->configResolved = $config !== null;
    }

    /**
     * Wählt eine andere App aus der Config.
     */
    public function app(string $name): static
    {
        return new static($this->container, $this->configResolved ? $this->config : null, $name);
    }

    public function appName(): string
    {
        $default = $this->config()['default'] ?? 'main';

        return $this->appName ?? (is_string($default) ? $default : 'main');
    }

    public function appConfig(): AppConfig
    {
        $apps = $this->config()['apps'] ?? [];
        $name = $this->appName();
        $config = is_array($apps) ? ($apps[$name] ?? null) : null;

        if (! is_array($config)) {
            throw new ConfigurationException("Meta app [{$name}] is not configured.");
        }

        return AppConfig::fromArray($name, $config);
    }

    public function asPage(PageConnection|AccessToken|string $pageOrToken): GraphClient
    {
        return match (true) {
            $pageOrToken instanceof PageConnection => $this->client($pageOrToken->accessToken, $pageOrToken->id),
            $pageOrToken instanceof AccessToken => $this->client($pageOrToken),
            default => $this->client(AccessToken::page($pageOrToken)),
        };
    }

    /**
     * Client mit dem gespeicherten Page Token einer Seite (TokenRepository).
     */
    public function asStoredPage(string $pageId): GraphClient
    {
        $token = $this->tokenRepository()->find(self::OWNER_PAGE, $pageId)
            ?? throw new ConfigurationException("No stored token for page [{$pageId}].");

        return $this->client($token, $pageId);
    }

    public function asUser(AccessToken|string $token): GraphClient
    {
        return $this->client(is_string($token) ? AccessToken::user($token) : $token);
    }

    public function asApp(): GraphClient
    {
        return $this->client($this->appConfig()->appToken());
    }

    public function asSystemUser(AccessToken|string $token): GraphClient
    {
        return $this->client(is_string($token) ? AccessToken::systemUser($token) : $token);
    }

    /**
     * Client ohne Token (z. B. für OAuth-Endpunkte).
     */
    public function client(?AccessToken $token = null, ?string $pageId = null): GraphClient
    {
        $app = $this->appConfig();

        return new GraphClient(
            transport: $this->transport($app),
            token: $token,
            options: ClientOptions::fromConfig($this->config()),
            pageId: $pageId,
            appsecretProof: $this->appsecretProofEnabled(),
        );
    }

    public function login(): LoginFlow
    {
        /** @var Session $session */
        $session = $this->container->make('session.store');

        return new LoginFlow($this->client(), $session);
    }

    public function tokens(): TokenExchanger
    {
        return new TokenExchanger($this->client());
    }

    public function debugger(): TokenDebugger
    {
        return new TokenDebugger($this->asApp());
    }

    public function tokenRepository(): TokenRepository
    {
        return $this->container->make(TokenRepository::class);
    }

    /**
     * Speichert den Page Token einer Seite und feuert PageConnected.
     */
    public function connectPage(PageConnection $page): void
    {
        $this->tokenRepository()->store(self::OWNER_PAGE, $page->id, $page->accessToken);

        Dispatch::event(new PageConnected($page, $this->appName()));
    }

    /**
     * Entfernt den Page Token einer Seite und feuert PageDisconnected.
     */
    public function disconnectPage(string $pageId): void
    {
        $this->tokenRepository()->forget(self::OWNER_PAGE, $pageId);

        Dispatch::event(new PageDisconnected($pageId, $this->appName()));
    }

    public function rateLimits(): RateLimitTracker
    {
        return $this->container->make(RateLimitTracker::class);
    }

    /**
     * Ersetzt die HTTP-Schicht durch Fakes mit Graph-Semantik.
     *
     * @param  array<string, mixed>  $stubs
     */
    public function fake(array $stubs = []): GraphFake
    {
        return GraphFake::install($stubs);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function config(): array
    {
        if ($this->configResolved) {
            return $this->config;
        }

        $config = $this->container->make(Repository::class)->get('meta-graph', []);

        return is_array($config) ? $config : [];
    }

    protected function transport(AppConfig $app): Transport
    {
        $http = is_array($this->config()['http'] ?? null) ? $this->config()['http'] : [];
        $retry = is_array($this->config()['retry'] ?? null) ? $this->config()['retry'] : [];
        $timeout = $http['timeout'] ?? 30;
        $connectTimeout = $http['connect_timeout'] ?? 10;

        return new Transport(
            app: $app,
            retry: RetryPolicy::fromConfig($retry),
            tracker: $this->rateLimits(),
            appsecretProof: $this->appsecretProofEnabled(),
            timeout: is_numeric($timeout) ? (int) $timeout : 30,
            connectTimeout: is_numeric($connectTimeout) ? (int) $connectTimeout : 10,
            logger: $this->logger(),
        );
    }

    protected function appsecretProofEnabled(): bool
    {
        return (bool) ($this->config()['appsecret_proof'] ?? true);
    }

    protected function logger(): ?LoggerInterface
    {
        $logging = is_array($this->config()['logging'] ?? null) ? $this->config()['logging'] : [];

        if (! ($logging['log_requests'] ?? false)) {
            return null;
        }

        /** @var LogManager $log */
        $log = $this->container->make('log');
        $channel = $logging['channel'] ?? null;

        return $log->channel(is_string($channel) ? $channel : null);
    }

    /**
     * Erzeugt einen RateLimitTracker gemäß Config.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function makeTracker(CacheFactory $cache, array $config): RateLimitTracker
    {
        $rateLimit = is_array($config['rate_limit'] ?? null) ? $config['rate_limit'] : [];
        $store = $rateLimit['cache_store'] ?? null;
        $warnAt = $rateLimit['warn_at'] ?? 80;

        return new RateLimitTracker(
            $cache,
            is_string($store) ? $store : null,
            is_numeric($warnAt) ? (int) $warnAt : 80,
        );
    }
}
