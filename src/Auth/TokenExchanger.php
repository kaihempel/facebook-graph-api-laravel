<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Auth;

use Illuminate\Support\Collection;
use Kuweh\MetaGraph\Data\PageConnection;
use Kuweh\MetaGraph\Graph\GraphClient;

/**
 * Tauscht kurzlebige User Tokens gegen Long-lived Tokens (ca. 60 Tage) und
 * liest daraus Page Tokens ohne Ablaufdatum.
 *
 * @see https://developers.facebook.com/docs/facebook-login/guides/access-tokens/get-long-lived
 */
final readonly class TokenExchanger
{
    public function __construct(
        private GraphClient $client,
    ) {}

    public function toLongLived(AccessToken|string $token): AccessToken
    {
        $token = is_string($token) ? AccessToken::user($token) : $token;
        $app = $this->client->app();

        $response = $this->client->send(
            $this->client->request('GET', 'oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $app->appId,
                'client_secret' => $app->appSecret,
                'fb_exchange_token' => $token->value,
            ])->withToken(null),
        );

        $longLived = AccessToken::fromOAuthResponse($response->raw(), $token->type);

        return new AccessToken($longLived->value, $longLived->type, $longLived->expiresAt, $token->scopes);
    }

    /**
     * Seiten des Users mit Page-ID, Name, Kategorie, Tasks und Page Token (paginiert).
     *
     * @return Collection<int, PageConnection>
     */
    public function pageTokens(AccessToken|string $userToken, string $userId = 'me'): Collection
    {
        $userToken = is_string($userToken) ? AccessToken::user($userToken) : $userToken;

        return $this->client->withToken($userToken)->pages()->connected($userId);
    }
}
