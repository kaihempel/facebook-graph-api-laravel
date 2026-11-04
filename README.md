# Laravel Meta Graph

[![Tests](https://github.com/kaihempel/facebook-graph-api-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/kaihempel/facebook-graph-api-laravel/actions/workflows/tests.yml)
[![Static Analysis](https://github.com/kaihempel/facebook-graph-api-laravel/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/kaihempel/facebook-graph-api-laravel/actions/workflows/static-analysis.yml)

Eigenständiger, typisierter Laravel-Client für die **Meta Graph API** mit Fokus auf das Veröffentlichen und Verwalten von Inhalten auf **Facebook-Seiten**. Ohne Meta-SDK, auf Basis des Laravel HTTP Clients – `Http::fake()`, Laravel-Events und Queues funktionieren nativ.

- Generischer Graph-Client: beliebige Nodes und Edges per GET, POST, DELETE inkl. Fields, Field Expansion, Pagination und Batch Requests
- OAuth (Facebook Login), Long-lived User Tokens, Page Access Tokens, Token-Debugging
- Seiten, Feed-Posts (Text, Link, geplant), Fotos, Mehrfachfoto-Posts, Videos, Reels, Kommentare, Insights
- Webhooks mit Verifizierung, Signaturprüfung und Laravel-Events
- Fehler-, Rate-Limit- und Retry-Behandlung, Jobs, Artisan-Commands, Test-Fakes

**Anforderungen:** PHP ≥ 8.3, Laravel 12 oder 13. Standardversion der Graph API: `v26.0`.

## Installation

```bash
composer require kuweh/laravel-meta-graph
php artisan meta-graph:install
```

Der Install-Command publiziert `config/meta-graph.php` sowie die Migrationen für `meta_graph_tokens` und `meta_graph_uploads`.

```dotenv
META_APP_ID=
META_APP_SECRET=
META_REDIRECT_URI=https://example.com/meta/callback
META_WEBHOOK_VERIFY_TOKEN=
META_GRAPH_VERSION=v26.0
```

Weitere Apps (Multi-Tenant) werden unter `apps` ergänzt und mit `MetaGraph::app('kunde-b')` ausgewählt.

## Token-Kontext

Jeder Aufruf läuft mit einem explizit benannten Token – es gibt keinen globalen Zustand.

```php
use KaiHempel\MetaGraph\Facades\MetaGraph;

MetaGraph::asPage($pageConnection);   // PageConnection (kennt Page-ID und Page Token)
MetaGraph::asPage('PAGE_TOKEN');      // nur Token: Ressourcen nutzen /me (= die Seite)
MetaGraph::asStoredPage('PAGE_ID');   // Token aus dem TokenRepository
MetaGraph::asUser($userToken);
MetaGraph::asApp();                   // {app-id}|{app-secret}
MetaGraph::asSystemUser($token);
MetaGraph::app('kunde-b')->asApp();   // andere App aus der Config
```

`appsecret_proof` wird automatisch an jeden Call mit Token angehängt (auch in Batch Requests) und lässt sich mit `appsecret_proof => false` abschalten.

## Facebook Login und Page Tokens

```php
// 1. Redirect zum Login-Dialog (State wird in der Session gespeichert)
return MetaGraph::login()->redirect(['pages_show_list', 'pages_manage_posts']);

// 2. Callback: State prüfen, Code gegen kurzlebigen User Token tauschen
$shortLived = MetaGraph::login()->handleCallback($request);

// 3. Long-lived User Token (ca. 60 Tage)
$userToken = MetaGraph::tokens()->toLongLived($shortLived);

// 4. Page Tokens ohne Ablaufdatum
foreach (MetaGraph::tokens()->pageTokens($userToken) as $page) {
    MetaGraph::connectPage($page); // speichert den Token, feuert PageConnected
}
```

Wer Laravel Socialite nutzt, übergibt dessen Token direkt an `toLongLived()`. `MetaGraph::debugger()->inspect($token)` liefert ein `TokenInfo` (gültig, Ablauf, Scopes, Typ, App-ID, User-ID).

## Low-Level-Client

```php
use KaiHempel\MetaGraph\Graph\FieldSet;

$response = MetaGraph::asPage($page)
    ->node($page->id)
    ->fields(['id', 'name', 'fan_count'])
    ->get();

$response->json('name');
$response->usage(); // RateLimitUsage aus X-App-Usage / X-Business-Use-Case-Usage

// Read-after-Write: POST mit fields liefert direkt das neue Objekt
MetaGraph::asPage($page)->node($page->id)->edge('feed')
    ->fields(['id', 'created_time', 'message'])
    ->post(['message' => 'Hallo']);

MetaGraph::asPage($page)->node($postId)->delete();

// Version pro Request überschreiben
MetaGraph::asPage($page)->me()->version('v25.0')->get();

// Field Expansion
FieldSet::make('id', 'message', 'created_time')
    ->expand('comments', FieldSet::make('id', 'message', 'from')->limit(25)->summary())
    ->expand('attachments', FieldSet::make('media_type', 'url'));
// id,message,created_time,comments.limit(25).summary(true){id,message,from},attachments{media_type,url}
```

PHP-Arrays werden als JSON-String übergeben, `bool` als `true`/`false`, `DateTimeInterface` als Unix-Timestamp.

### Pagination

```php
$page = MetaGraph::asPage($p)->node($p->id)->edge('feed')->paginate(limit: 25);
$page->items; $page->nextCursor; $page->hasMore;

// LazyCollection, folgt paging.next automatisch (begrenzt durch pagination.max_pages)
MetaGraph::asPage($p)->node($p->id)->edge('feed')->since(now()->subWeek())->cursor()
    ->each(fn (array $post) => ...);

// Mehrere Nodes in einem Request (?ids=)
MetaGraph::asUser($t)->nodes(['id1', 'id2'])->get();
```

### Batch Requests

```php
use KaiHempel\MetaGraph\Graph\Batch\BatchRequest;

$results = MetaGraph::asUser($token)->batch(function (BatchRequest $batch) use ($page, $upload, $otherPage) {
    $batch->post("{$page->id}/feed", ['message' => 'Test'])->name('create');
    $batch->get('?ids={result=create:$.id}')->dependsOn('create');
    $batch->post("{$page->id}/photos", ['caption' => 'Foto'])->attach('file1', $upload);
    $batch->get($otherPage->id)->withToken($otherPage->accessToken);
});

foreach ($results as $name => $item) {
    $item->successful(); $item->status(); $item->json(); $item->exception();
}

$results->failed()->retry(); // z. B. Timeouts erneut senden
```

Mehr als 50 Operationen werden automatisch in Chunks geteilt; `batchStrict()` wirft stattdessen eine `BatchTooLargeException`. Abhängigkeiten über Chunk-Grenzen hinweg löst Meta nicht auf.

## Ressourcen

```php
use KaiHempel\MetaGraph\Media\MediaSource;
use KaiHempel\MetaGraph\Resources\Posts\PostDraft;

$graph = MetaGraph::asPage($page);

// Seiten
MetaGraph::asUser($userToken)->pages()->connected();
$graph->pages()->find($page->id);
$graph->pages()->subscribeApp($page, ['feed', 'mention']);

// Posts
$graph->posts()->create('Hallo Welt');
$graph->posts()->create(PostDraft::make('Mit Link')->link('https://example.com'));
$graph->posts()->update($postId, 'Neuer Text');
$graph->posts()->delete($postId);
$graph->posts()->feed(since: now()->subWeek());   // inkl. is_published
$graph->posts()->scheduled();

// Mehrfachfoto-Post planen
$post = $graph->posts()->schedule(
    PostDraft::make()
        ->message('Drei Eindrücke von der Eröffnung')
        ->photos([
            MediaSource::disk('s3', 'events/1.jpg'),
            MediaSource::disk('s3', 'events/2.jpg'),
        ]),
    publishAt: now()->addDays(2)->setTime(9, 0),
);
$post->id; $post->scheduledFor;

// Fotos
$graph->photos()->publish(MediaSource::url('https://cdn.example.com/a.jpg'), 'Bildunterschrift');
$graph->photos()->uploadUnpublished(MediaSource::file($path), temporary: true);

// Videos (Resumable Upload: Session mit User Token, Publish mit Page Token)
$video = $graph->videos()->publish(MediaSource::disk('s3', 'clip.mp4'), title: 'Titel', uploadToken: $userToken);
$graph->videos()->status($video->id);

// Reels: start → Upload an rupload.facebook.com → finish
$reel = $graph->reels()->publish(MediaSource::file($path), description: 'Neu #laravel');
$graph->reels()->status($reel->id)->throwIfFailed();

// Kommentare
$graph->comments()->list($postId)->each(...);
$graph->comments()->reply($postId, 'Danke!');
$graph->comments()->hide($commentId);
$graph->comments()->delete($commentId);

// Insights
use Carbon\CarbonPeriod;
use KaiHempel\MetaGraph\Resources\Insights\PageMetric;

$graph->insights()->page([PageMetric::MediaView, PageMetric::Follows], CarbonPeriod::create('2026-10-01', '2026-10-31'));
$graph->insights()->post($postId, ['post_media_view']);
```

**Medienquellen:** `MediaSource::url()` (Meta lädt die Datei selbst; Hosts mit `robots.txt`-Sperre werden abgelehnt), `MediaSource::file()`, `MediaSource::disk()` (gestreamt, ohne den Inhalt komplett zu laden), `MediaSource::upload(UploadedFile)` und `MediaSource::contents()`.

**Hinweise:**

- Geplante Posts müssen standardmäßig 10 Minuten bis 30 Tage in der Zukunft liegen (`scheduling` in der Config, da Metas Doku widersprüchlich ist).
- Unveröffentlichte Fotos verwirft Meta nach ca. 24 h. Schlägt der Feed-Post nach dem Foto-Upload fehl, meldet `PostFailed` die IDs der hochgeladenen Fotos.
- Meta erlaubt max. 30 API-Reels pro Seite in 24 h.
- Video- und Reel-Uploads setzen nach Abbrüchen am serverseitigen Offset fort. Publish-Schritte werden nie blind wiederholt, sondern vorher per Status-Abfrage geprüft.
- Der ältere Chunk-Flow auf `graph-video.facebook.com` ist per `uploads.video_driver = chunked` verfügbar.

## Fehlerbehandlung

Graph-Fehler werden anhand von `code` und `error_subcode` in genau eine Exception übersetzt. Alle erben von `GraphException` (`type`, `getCode()`, `subcode`, `userTitle`, `userMessage`, `fbtraceId`, `httpStatus`, `request` ohne Token, `isTransient()`, `shouldReauthenticate()`).

| Exception | Codes / Subcodes | Reaktion |
| --- | --- | --- |
| `TransientException` | 1, 2, Verbindungsfehler, HTTP 5xx | Automatischer Retry mit Backoff (1 s, 4 s, 16 s + Jitter) |
| `RateLimitException` | 4, 17, 32, 341, 613, 80001 | Kein Retry im selben Prozess; `retryAfter()` aus `estimated_time_to_regain_access` |
| `AuthenticationException` | 102, 190, OAuthException ohne Subcode; 458, 463, 467 | Event `TokenInvalidated` |
| `UserActionRequiredException` | 459, 460, 464 | Event `UserActionRequired` mit Hinweistext |
| `PageRoleException` | 492 | Fehlende Rolle auf der Seite |
| `PermissionException` | 3, 10, 200–299 | `missingScope()`, falls ermittelbar |
| `PolicyBlockException` | 368 | Später erneut versuchen |
| `DuplicatePostException` | 506 | Inhalt ändern |
| `LinkScrapeException` | 1609005 | URL prüfen |
| `MediaProcessingException` | Fehler in der `processing_phase` | Meldung an den Nutzer |
| `GraphException` | alles andere, z. B. 100 | Kein Retry |

Rate-Limit-Header werden nach jeder Antwort im Cache gehalten (`MetaGraph::rateLimits()`). Ab `rate_limit.warn_at` Prozent feuert `RateLimitApproaching`. Tokens und `appsecret_proof` werden in Logs und Exceptions maskiert.

## Webhooks

Die Route `GET|POST /meta-graph/webhook` (Pfad und Middleware konfigurierbar, ohne CSRF) beantwortet den Verifizierungs-Handshake, prüft `X-Hub-Signature-256` auf dem rohen Body und verarbeitet die Zustellung als Job `ProcessWebhookPayload`. Für weitere Apps: `/meta-graph/webhook/{app}`.

```php
use KaiHempel\MetaGraph\Events\PageCommentReceived;

Event::listen(function (PageCommentReceived $event) {
    $event->change->objectId;      // Page-ID
    $event->change->get('message');
});
```

Events: `WebhookReceived` (generisch), `PageFeedChanged`, `PageCommentReceived`, `PageMentionReceived`. Replays werden über einen Hash im Cache dedupliziert (`webhooks.dedupe_ttl`). Abonnieren: `$graph->pages()->subscribeApp($page, ['feed', 'mention'])` bzw. app-weit `php artisan meta-graph:webhooks:subscribe`.

## Jobs, Events und Commands

**Jobs:** `PublishPost`, `UploadVideo` (fortsetzbar über persistierte Session), `PublishReel` (Upload, Status-Polling mit `release()`, Finish), `ProcessWebhookPayload`, `RefreshPageTokens`. Die Job-Middleware `RespectMetaRateLimits` stellt Jobs bei Rate-Limit-Sperren zurück. Jobs mit Tokens werden verschlüsselt in die Queue gelegt.

```php
PublishPost::dispatch($pageId, PostDraft::make('Aus der Queue'), publishAt: now()->addDay());
```

**Events:** `PageConnected`, `PageDisconnected`, `TokenExpiringSoon`, `TokenInvalidated`, `UserActionRequired`, `PostPublished`, `PostScheduled`, `PostFailed`, `MediaUploadProgressed`, `MediaProcessingFinished`, `RateLimitApproaching`, `RateLimitHit` sowie die Webhook-Events.

**Commands:**

```bash
php artisan meta-graph:install
php artisan meta-graph:tokens:check          # für den Scheduler, täglich
php artisan meta-graph:token:debug {token?}
php artisan meta-graph:pages --user=42 --connect
php artisan meta-graph:webhooks:subscribe --fields=feed,mention
php artisan meta-graph:version:check
```

```php
// routes/console.php
Schedule::command('meta-graph:tokens:check')->daily();
Schedule::command('meta-graph:version:check')->weekly();
```

## Testen mit GraphFake

```php
use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\Testing\GraphFake;

$fake = MetaGraph::fake([
    'v26.0/*/feed' => GraphFake::response(['id' => '123_456']),
    'v26.0/*/video_reels' => GraphFake::sequence()
        ->push(['video_id' => '999', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/999'])
        ->push(['success' => true]),
    'v26.0/*/photos' => GraphFake::error(code: 32, message: 'Page request limit reached'),
    'v26.0/me/accounts' => GraphFake::paginated([[...], [...]]),
    'v26.0' => GraphFake::batch([['id' => '1'], null /* Timeout */]),
]);

// ... Code ausführen ...

$fake->assertPosted('*/feed', fn ($params) => $params['message'] === 'Hallo');
$fake->assertBatchSent(count: 3); // Anzahl der Operationen
$fake->assertNothingSent();
```

`GraphFake` baut auf `Http::fake()` auf und kennt Fehler-Payloads im Meta-Format, Usage-Header (`->withUsage()`), Batch-Antworten mit JSON-kodierten Bodies und Pagination-Sequenzen.

## Entwicklung

```bash
composer test           # Pest
composer test-coverage  # Pest mit Coverage
composer analyse        # PHPStan Level max (Larastan)
composer lint           # Pint
```

Optionale Live-Tests gegen eine eigene Testseite laufen mit `vendor/bin/pest --group=live` und den Umgebungsvariablen `META_LIVE_APP_ID`, `META_LIVE_APP_SECRET`, `META_LIVE_PAGE_ID`, `META_LIVE_PAGE_TOKEN`.

Die GitHub Actions testen PHP 8.3/8.4 × Laravel 12/13 jeweils mit `--prefer-lowest` und `--prefer-stable`, prüfen Pint und PHPStan und erzwingen eine Coverage von mindestens 90 % für `src/Graph`, `src/Auth` und `src/Exceptions`.

## Lizenz

MIT, siehe [LICENSE.md](LICENSE.md).
