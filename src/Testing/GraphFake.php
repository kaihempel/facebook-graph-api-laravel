<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Testing;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Test-Fake mit Graph-Semantik auf Basis von Http::fake(): Fehler-Payloads im
 * Meta-Format, Usage-Header, Batch-Antworten und Pagination-Sequenzen.
 */
final class GraphFake
{
    /** @var array<string, mixed> */
    private array $stubs = [];

    /** Aktive Instanz; ein erneutes fake() ersetzt die vorherige. */
    private static ?self $current = null;

    /** @var \WeakReference<object>|null HTTP-Factory, auf der der Handler registriert ist */
    private static ?\WeakReference $factory = null;

    /**
     * @param  array<string, mixed>  $stubs  Pfad-Muster (z. B. "v26.0/*\/feed") => Antwort
     */
    public function __construct(array $stubs = [])
    {
        $this->stub($stubs);
    }

    /**
     * @param  array<string, mixed>  $stubs
     */
    public static function install(array $stubs = []): self
    {
        $fake = new self($stubs);
        $factory = Http::getFacadeRoot();

        self::$current = $fake;

        if (! is_object($factory) || self::$factory?->get() !== $factory) {
            Http::fake(static fn (Request $request): PromiseInterface => (self::$current ?? new self)->handle($request));
            $factory = Http::getFacadeRoot();
            self::$factory = is_object($factory) ? \WeakReference::create($factory) : null;
        }

        return $fake;
    }

    /**
     * Weitere Stubs registrieren (spätere Stubs haben Vorrang).
     *
     * @param  array<string, mixed>  $stubs
     */
    public function stub(array $stubs): self
    {
        $this->stubs = array_merge($stubs, $this->stubs);

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function response(array $data = [], int $status = 200, array $headers = []): FakeResponse
    {
        return new FakeResponse($data, $status, $headers);
    }

    /**
     * Fehler im Meta-Format: {"error": {message, type, code, error_subcode, fbtrace_id}}.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function error(
        int $code,
        string $message = 'Graph error',
        ?int $subcode = null,
        string $type = 'OAuthException',
        int $status = 400,
        array $extra = [],
    ): FakeResponse {
        return new FakeResponse([
            'error' => array_filter([
                'message' => $message,
                'type' => $type,
                'code' => $code,
                'error_subcode' => $subcode,
                'fbtrace_id' => 'FakeTrace'.$code,
                ...$extra,
            ], static fn (mixed $v): bool => $v !== null),
        ], $status);
    }

    public static function sequence(): FakeSequence
    {
        return new FakeSequence;
    }

    /**
     * Pagination-Sequenz: jede Seite bekommt paging.next mit Cursor, die letzte keine.
     *
     * @param  list<list<array<array-key, mixed>>>  $pages
     */
    public static function paginated(array $pages, string $baseUrl = 'https://graph.facebook.com/v26.0/next'): FakeSequence
    {
        $sequence = self::sequence();
        $count = count($pages);

        foreach ($pages as $index => $items) {
            $paging = ['cursors' => ['before' => 'cursor'.$index, 'after' => 'cursor'.($index + 1)]];

            if ($index < $count - 1) {
                $paging['next'] = $baseUrl.'?after=cursor'.($index + 1).'&access_token=secret';
            }

            $sequence->push(['data' => $items, 'paging' => $paging]);
        }

        return $sequence;
    }

    /**
     * Batch-Antwort mit JSON-kodierten Bodies; null steht für einen Timeout.
     *
     * @param  list<FakeResponse|array<array-key, mixed>|null>  $items
     */
    public static function batch(array $items): FakeResponse
    {
        return new FakeResponse(array_map(
            static fn (FakeResponse|array|null $item): ?array => match (true) {
                $item === null => null,
                $item instanceof FakeResponse => $item->toBatchItem(),
                default => (new FakeResponse($item))->toBatchItem(),
            },
            $items,
        ));
    }

    public function handle(Request $request): PromiseInterface
    {
        $recorded = RecordedRequest::fromRequest($request);

        foreach ($this->stubs as $pattern => $stub) {
            if ($recorded->matches($pattern)) {
                return $this->resolve($stub, $recorded)->toPromise();
            }
        }

        return self::error(
            code: 100,
            message: "GraphFake: no stub registered for [{$recorded->method} {$recorded->path}].",
            type: 'GraphMethodException',
            status: 404,
        )->toPromise();
    }

    /**
     * @return list<RecordedRequest>
     */
    public function recorded(?Closure $filter = null): array
    {
        $requests = [];

        foreach (Http::recorded() as [$request]) {
            $recorded = RecordedRequest::fromRequest($request);

            if (str_ends_with($recorded->host, 'facebook.com') && ($filter === null || $filter($recorded))) {
                $requests[] = $recorded;
            }
        }

        return $requests;
    }

    /**
     * @param  (Closure(array<string, mixed>, RecordedRequest): bool)|null  $callback
     */
    public function assertSent(string $pattern, ?Closure $callback = null, ?string $method = null): self
    {
        PHPUnit::assertNotEmpty(
            $this->matching($pattern, $callback, $method),
            "No Graph request matching [{$pattern}] was sent.",
        );

        return $this;
    }

    /**
     * @param  (Closure(array<string, mixed>, RecordedRequest): bool)|null  $callback
     */
    public function assertPosted(string $pattern, ?Closure $callback = null): self
    {
        return $this->assertSent($pattern, $callback, 'POST');
    }

    /**
     * @param  (Closure(array<string, mixed>, RecordedRequest): bool)|null  $callback
     */
    public function assertFetched(string $pattern, ?Closure $callback = null): self
    {
        return $this->assertSent($pattern, $callback, 'GET');
    }

    /**
     * @param  (Closure(array<string, mixed>, RecordedRequest): bool)|null  $callback
     */
    public function assertDeleted(string $pattern, ?Closure $callback = null): self
    {
        return $this->assertSent($pattern, $callback, 'DELETE');
    }

    /**
     * @param  (Closure(array<string, mixed>, RecordedRequest): bool)|null  $callback
     */
    public function assertNotSent(string $pattern, ?Closure $callback = null): self
    {
        PHPUnit::assertEmpty(
            $this->matching($pattern, $callback),
            "An unexpected Graph request matching [{$pattern}] was sent.",
        );

        return $this;
    }

    public function assertNothingSent(): self
    {
        PHPUnit::assertEmpty($this->recorded(), 'Graph requests were sent unexpectedly.');

        return $this;
    }

    public function assertSentCount(int $count): self
    {
        PHPUnit::assertCount($count, $this->recorded());

        return $this;
    }

    /**
     * Prüft, dass ein Batch gesendet wurde; mit $count die Gesamtzahl der Operationen.
     *
     * @param  (Closure(list<array<array-key, mixed>>): bool)|null  $callback
     */
    public function assertBatchSent(?int $count = null, ?Closure $callback = null): self
    {
        $batches = $this->recorded(static fn (RecordedRequest $r): bool => $r->isBatch());

        PHPUnit::assertNotEmpty($batches, 'No Graph batch request was sent.');

        if ($count !== null) {
            $operations = array_sum(array_map(static fn (RecordedRequest $r): int => count($r->batchOperations()), $batches));
            PHPUnit::assertSame($count, $operations, "Expected {$count} batch operations, {$operations} sent.");
        }

        if ($callback !== null) {
            $matched = array_filter($batches, static fn (RecordedRequest $r): bool => $callback($r->batchOperations()));
            PHPUnit::assertNotEmpty($matched, 'No batch request matched the given callback.');
        }

        return $this;
    }

    /**
     * @param  (Closure(array<string, mixed>, RecordedRequest): bool)|null  $callback
     * @return list<RecordedRequest>
     */
    private function matching(string $pattern, ?Closure $callback, ?string $method = null): array
    {
        return $this->recorded(static fn (RecordedRequest $r): bool => ($method === null || $r->method === $method)
            && $r->matches($pattern)
            && ($callback === null || $callback($r->params, $r)));
    }

    private function resolve(mixed $stub, RecordedRequest $request): FakeResponse
    {
        if ($stub instanceof Closure) {
            $stub = $stub($request->params, $request);
        }

        return match (true) {
            $stub instanceof FakeResponse => $stub,
            $stub instanceof FakeSequence => $stub->next(),
            is_array($stub) => new FakeResponse($stub),
            default => new FakeResponse(['success' => true]),
        };
    }
}
