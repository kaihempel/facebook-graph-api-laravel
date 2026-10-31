<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Auth\OAuth;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Exceptions\ConfigurationException;
use Kuweh\MetaGraph\Exceptions\InvalidStateException;
use Kuweh\MetaGraph\Exceptions\LoginFailedException;
use Kuweh\MetaGraph\Graph\GraphClient;

/**
 * Facebook Login: Login-URL bauen, State (CSRF) prüfen, Code gegen User Token tauschen.
 */
final readonly class LoginFlow
{
    public function __construct(
        private GraphClient $client,
        private Session $session,
    ) {}

    /**
     * @param  list<string>|null  $scopes  Standard: Scopes der App aus der Config
     * @param  array<string, string>  $extra  z. B. auth_type=rerequest, config_id
     */
    public function redirectUrl(?array $scopes = null, ?string $state = null, array $extra = []): string
    {
        $app = $this->client->app();
        $state ??= Str::random(40);
        $this->session->put($this->stateKey(), $state);

        $query = http_build_query(array_merge([
            'client_id' => $app->appId,
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(',', $scopes ?? $app->scopes),
            'state' => $state,
            'response_type' => 'code',
        ], $extra), '', '&', PHP_QUERY_RFC3986);

        return 'https://www.facebook.com/'.$this->client->version().'/dialog/oauth?'.$query;
    }

    /**
     * @param  list<string>|null  $scopes
     * @param  array<string, string>  $extra
     */
    public function redirect(?array $scopes = null, ?string $state = null, array $extra = []): RedirectResponse
    {
        return new RedirectResponse($this->redirectUrl($scopes, $state, $extra));
    }

    /**
     * Prüft den State und tauscht den Code gegen einen kurzlebigen User Token.
     *
     * @throws InvalidStateException
     * @throws LoginFailedException
     */
    public function handleCallback(Request $request): AccessToken
    {
        $expected = $this->session->pull($this->stateKey());
        $state = $request->query('state');

        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new InvalidStateException('The OAuth state does not match the session.');
        }

        $error = $request->query('error');
        if (is_string($error)) {
            $description = $request->query('error_description');
            $reason = $request->query('error_reason');

            throw new LoginFailedException(
                is_string($description) ? $description : 'Facebook login failed.',
                $error,
                is_string($reason) ? $reason : null,
            );
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            throw new LoginFailedException('The callback did not contain an authorization code.');
        }

        return $this->exchangeCode($code);
    }

    /**
     * GET /oauth/access_token mit client_id, redirect_uri, client_secret und code.
     */
    public function exchangeCode(string $code): AccessToken
    {
        $app = $this->client->app();

        $response = $this->client->send(
            $this->client->request('GET', 'oauth/access_token', [
                'client_id' => $app->appId,
                'redirect_uri' => $this->redirectUri(),
                'client_secret' => $app->appSecret,
                'code' => $code,
            ])->withToken(null),
        );

        return AccessToken::fromOAuthResponse($response->raw());
    }

    private function redirectUri(): string
    {
        return $this->client->app()->redirect
            ?? throw new ConfigurationException("Meta app [{$this->client->app()->name}] has no redirect URI configured.");
    }

    private function stateKey(): string
    {
        return 'meta-graph.oauth_state.'.$this->client->app()->name;
    }
}
