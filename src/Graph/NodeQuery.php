<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Graph;

use DateTimeInterface;
use Illuminate\Support\LazyCollection;
use KaiHempel\MetaGraph\Contracts\MediaSource;
use KaiHempel\MetaGraph\Data\CursorPage;

/**
 * Fluenter Zugriff auf Nodes und Edges: $graph->node('ID')->edge('feed')->fields([...])->get().
 * Jede Methode liefert eine neue Instanz.
 */
final readonly class NodeQuery
{
    public function __construct(
        private GraphClient $client,
        private GraphRequest $request,
    ) {}

    public function edge(string $edge): self
    {
        return new self($this->client, $this->request->edge($edge));
    }

    /**
     * @param  FieldSet|list<string>|string  $fields
     */
    public function fields(FieldSet|array|string $fields): self
    {
        return new self($this->client, $this->request->fields($fields));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function with(array $params): self
    {
        return new self($this->client, $this->request->withParams($params));
    }

    public function limit(int $limit): self
    {
        return $this->with(['limit' => $limit]);
    }

    public function since(DateTimeInterface|int $since): self
    {
        return $this->with(['since' => $since]);
    }

    public function until(DateTimeInterface|int $until): self
    {
        return $this->with(['until' => $until]);
    }

    public function version(string $version): self
    {
        return new self($this->client, $this->request->version($version));
    }

    public function host(string $host): self
    {
        return new self($this->client, $this->request->host($host));
    }

    public function attach(string $name, MediaSource $file): self
    {
        return new self($this->client, $this->request->attach($name, $file));
    }

    public function request(): GraphRequest
    {
        return $this->request;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function get(array $params = []): GraphResponse
    {
        return $this->client->send($this->request->withMethod('GET')->withParams($params));
    }

    /**
     * Create bzw. Update. Mit gesetztem fields() liefert Meta direkt die Felder des Objekts (Read-after-Write).
     *
     * @param  array<string, mixed>  $params
     */
    public function post(array $params = []): GraphResponse
    {
        return $this->client->send($this->request->withMethod('POST')->withParams($params));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(array $params = []): GraphResponse
    {
        return $this->client->send($this->request->withMethod('DELETE')->withParams($params));
    }

    /**
     * Eine Seite der Edge (Items, nextCursor, hasMore).
     *
     * @return CursorPage<array<array-key, mixed>>
     */
    public function paginate(?int $limit = null, ?string $after = null): CursorPage
    {
        $request = $this->request->withMethod('GET');

        if ($limit !== null) {
            $request = $request->withParam('limit', $limit);
        }

        if ($after !== null) {
            $request = $request->withParam('after', $after);
        }

        return $this->client->paginator()->page($request);
    }

    /**
     * Alle Items der Edge; folgt paging.next automatisch (begrenzt durch max_pages).
     *
     * @return LazyCollection<int, array<array-key, mixed>>
     */
    public function cursor(?int $maxPages = null): LazyCollection
    {
        return $this->client->paginator()->cursor($this->request->withMethod('GET'), $maxPages);
    }
}
