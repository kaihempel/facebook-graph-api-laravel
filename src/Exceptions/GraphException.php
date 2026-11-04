<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Exceptions;

use KaiHempel\MetaGraph\Graph\GraphRequest;
use RuntimeException;
use Throwable;

/**
 * Basisklasse für alle Fehler, die die Graph API liefert.
 *
 * Unterklassen sagen dem Aufrufer, was zu tun ist: wiederholen
 * (isTransient), neu autorisieren (shouldReauthenticate) oder den
 * Inhalt ändern.
 */
class GraphException extends RuntimeException implements MetaGraphException
{
    /**
     * @var array{method: string, url: string, params: array<array-key, mixed>, token_type: string|null}|null
     */
    public readonly ?array $request;

    /**
     * @param  array<array-key, mixed>  $payload  Rohes "error"-Objekt der Antwort
     */
    public function __construct(
        string $message,
        public readonly string $type = 'GraphMethodException',
        int $code = 0,
        public readonly ?int $subcode = null,
        public readonly ?string $userTitle = null,
        public readonly ?string $userMessage = null,
        public readonly ?string $fbtraceId = null,
        public readonly int $httpStatus = 0,
        ?GraphRequest $request = null,
        public readonly array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);

        $this->request = $request?->toSafeArray();
    }

    public function isTransient(): bool
    {
        return false;
    }

    public function shouldReauthenticate(): bool
    {
        return false;
    }

    public function subcode(): ?int
    {
        return $this->subcode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'type' => $this->type,
            'code' => $this->getCode(),
            'subcode' => $this->subcode,
            'fbtrace_id' => $this->fbtraceId,
            'http_status' => $this->httpStatus,
            'request' => $this->request,
        ];
    }
}
