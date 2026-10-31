<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Auth;

/**
 * appsecret_proof = HMAC-SHA256(access_token, app_secret).
 *
 * @see https://developers.facebook.com/docs/graph-api/guides/secure-requests
 */
final class AppSecretProof
{
    public static function make(AccessToken|string $accessToken, string $appSecret): string
    {
        $value = $accessToken instanceof AccessToken ? $accessToken->value : $accessToken;

        return hash_hmac('sha256', $value, $appSecret);
    }

    public static function verify(AccessToken|string $accessToken, string $appSecret, string $proof): bool
    {
        return hash_equals(self::make($accessToken, $appSecret), $proof);
    }
}
