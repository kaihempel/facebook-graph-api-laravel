<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Webhooks;

/**
 * Prüft X-Hub-Signature-256: HMAC-SHA256 über den unveränderten Request-Body
 * mit dem App Secret. Der veraltete SHA-1-Header X-Hub-Signature wird ignoriert.
 */
final class SignatureVerifier
{
    public const string HEADER = 'X-Hub-Signature-256';

    public static function sign(string $payload, string $appSecret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $appSecret);
    }

    public static function verify(string $rawBody, ?string $header, string $appSecret): bool
    {
        if ($header === null || $appSecret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $signature = substr($header, strlen('sha256='));

        return hash_equals(hash_hmac('sha256', $rawBody, $appSecret), $signature);
    }
}
