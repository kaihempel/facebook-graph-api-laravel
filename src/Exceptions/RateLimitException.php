<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Exceptions;

use KaiHempel\MetaGraph\Data\RateLimitUsage;
use KaiHempel\MetaGraph\Graph\GraphRequest;
use Throwable;

/**
 * Rate Limit erreicht (Codes 4, 17, 32, 341, 613, 80001).
 *
 * Wird bewusst nicht im selben Prozess wiederholt, weil jeder weitere
 * Call die Sperrzeit verlängert. Jobs werden mit release(retryAfter()) zurückgestellt.
 */
class RateLimitException extends GraphException
{
    public const int DEFAULT_RETRY_AFTER = 60;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        string $message,
        string $type = 'OAuthException',
        int $code = 4,
        ?int $subcode = null,
        ?string $userTitle = null,
        ?string $userMessage = null,
        ?string $fbtraceId = null,
        int $httpStatus = 0,
        ?GraphRequest $request = null,
        array $payload = [],
        ?Throwable $previous = null,
        public readonly ?RateLimitUsage $usage = null,
    ) {
        parent::__construct($message, $type, $code, $subcode, $userTitle, $userMessage, $fbtraceId, $httpStatus, $request, $payload, $previous);
    }

    /**
     * Wartezeit in Sekunden aus estimated_time_to_regain_access, sonst Default.
     */
    public function retryAfter(): int
    {
        $seconds = $this->usage?->secondsToRegainAccess() ?? 0;

        return $seconds > 0 ? $seconds : self::DEFAULT_RETRY_AFTER;
    }
}
