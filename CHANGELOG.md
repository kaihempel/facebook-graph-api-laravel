# Changelog

Alle nennenswerten Änderungen an diesem Paket werden hier dokumentiert.
Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionen folgen [SemVer](https://semver.org/lang/de/).

## [Unreleased]

### Added

- Generischer `GraphClient` mit Nodes, Edges, Fields/Field Expansion (`FieldSet`), Pagination (`paginate()`, `cursor()`), `?ids=` und Batch Requests (Chunking, Abhängigkeiten, Tokens pro Operation, Dateien, Timeouts mit `retry()`).
- Versionierung (Default `v26.0`), automatischer `appsecret_proof`, Parameter-Serialisierung nach Graph-Regeln.
- OAuth-Flow (`LoginFlow`) mit State-Prüfung, `TokenExchanger` (Long-lived und Page Tokens), `TokenDebugger`, `TokenRepository` mit Eloquent- und Array-Implementierung.
- Ressourcen für Seiten, Posts (inkl. geplanter Posts und Mehrfachfoto-Posts), Fotos, Videos (Resumable Upload API, Chunk-Fallback), Reels, Kommentare und Insights.
- Typisierte Exception-Hierarchie, Retry-Policy mit Backoff und Jitter, Rate-Limit-Tracking mit Events und Job-Middleware.
- Webhooks mit Verifizierung, Signaturprüfung, Deduplizierung und typisierten Events.
- Jobs, Artisan-Commands, `GraphFake` für Tests.
