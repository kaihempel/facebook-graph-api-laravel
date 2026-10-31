<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Exceptions;

use Kuweh\MetaGraph\Data\RateLimitUsage;
use Kuweh\MetaGraph\Graph\GraphRequest;
use Kuweh\MetaGraph\Graph\GraphResponse;

/**
 * Übersetzt einen Graph-Fehler anhand von code und error_subcode in genau eine Exception.
 *
 * @see https://developers.facebook.com/docs/graph-api/guides/error-handling
 */
final class ErrorMapper
{
    /** @var list<int> */
    public const array TRANSIENT_CODES = [1, 2];

    /** @var list<int> */
    public const array RATE_LIMIT_CODES = [4, 17, 32, 341, 613, 80001];

    /** @var list<int> */
    public const array AUTH_CODES = [102, 190];

    /** @var list<int> */
    public const array AUTH_SUBCODES = [458, 463, 467];

    /** @var list<int> */
    public const array USER_ACTION_SUBCODES = [459, 460, 464];

    public const int PAGE_ROLE_SUBCODE = 492;

    public const int POLICY_BLOCK_CODE = 368;

    public const int DUPLICATE_POST_CODE = 506;

    public const int LINK_SCRAPE_SUBCODE = 1609005;

    public static function fromResponse(GraphResponse $response, ?GraphRequest $request = null): GraphException
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            $error = [
                'message' => $response->status >= 500 ? "Graph API server error (HTTP {$response->status})." : "Unexpected Graph API response (HTTP {$response->status}).",
                'type' => 'HttpException',
                'code' => $response->status >= 500 ? 2 : 0,
            ];
        }

        return self::fromError($error, $response->status, $request ?? $response->request, $response->usage());
    }

    /**
     * @param  array<array-key, mixed>  $error
     */
    public static function fromError(array $error, int $httpStatus = 0, ?GraphRequest $request = null, ?RateLimitUsage $usage = null): GraphException
    {
        $str = static fn (string $key): ?string => isset($error[$key]) && is_scalar($error[$key]) ? (string) $error[$key] : null;
        $int = static fn (string $key): ?int => isset($error[$key]) && is_numeric($error[$key]) ? (int) $error[$key] : null;

        $message = $str('message') ?? 'Unknown Graph API error.';
        $type = $str('type') ?? 'GraphMethodException';
        $code = $int('code') ?? 0;
        $subcode = $int('error_subcode');
        $args = [
            $message,
            $type,
            $code,
            $subcode,
            $str('error_user_title'),
            $str('error_user_msg'),
            $str('fbtrace_id'),
            $httpStatus,
            $request,
            $error,
        ];

        $class = self::classFor($code, $subcode, $type, (bool) ($error['is_transient'] ?? false) || $httpStatus >= 500);

        if ($class === RateLimitException::class) {
            return new RateLimitException(...$args, usage: $usage);
        }

        return new $class(...$args);
    }

    /**
     * @param  bool  $isTransient  is_transient-Flag der Antwort oder HTTP 5xx
     * @return class-string<GraphException>
     */
    public static function classFor(int $code, ?int $subcode, string $type = '', bool $isTransient = false): string
    {
        // Subcodes sind spezifischer als Codes und werden zuerst geprüft.
        if ($subcode !== null) {
            if (in_array($subcode, self::USER_ACTION_SUBCODES, true)) {
                return UserActionRequiredException::class;
            }

            if (in_array($subcode, self::AUTH_SUBCODES, true)) {
                return AuthenticationException::class;
            }

            if ($subcode === self::PAGE_ROLE_SUBCODE) {
                return PageRoleException::class;
            }

            if ($subcode === self::LINK_SCRAPE_SUBCODE) {
                return LinkScrapeException::class;
            }
        }

        return match (true) {
            in_array($code, self::TRANSIENT_CODES, true) => TransientException::class,
            in_array($code, self::RATE_LIMIT_CODES, true) => RateLimitException::class,
            in_array($code, self::AUTH_CODES, true) => AuthenticationException::class,
            $code === 3, $code === 10, $code >= 200 && $code <= 299 => PermissionException::class,
            $code === self::POLICY_BLOCK_CODE => PolicyBlockException::class,
            $code === self::DUPLICATE_POST_CODE => DuplicatePostException::class,
            $type === 'OAuthException' && $subcode === null && $code !== 100 => AuthenticationException::class,
            $isTransient => TransientException::class,
            default => GraphException::class,
        };
    }
}
