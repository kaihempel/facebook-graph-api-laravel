<?php

declare(strict_types=1);

use Kuweh\MetaGraph\Auth\EloquentTokenRepository;
use Kuweh\MetaGraph\Uploads\EloquentUploadSessionStore;

return [

    /*
    |--------------------------------------------------------------------------
    | Standard-App
    |--------------------------------------------------------------------------
    |
    | Schlüssel der App aus "apps", die ohne MetaGraph::app('...') genutzt wird.
    |
    */

    'default' => env('META_GRAPH_APP', 'main'),

    'apps' => [
        'main' => [
            'app_id' => env('META_APP_ID'),
            'app_secret' => env('META_APP_SECRET'),
            'redirect' => env('META_REDIRECT_URI'),
            'scopes' => [
                'pages_show_list',
                'pages_manage_posts',
                'pages_read_engagement',
                'pages_read_user_engagement',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Graph-API-Version
    |--------------------------------------------------------------------------
    |
    | Ohne Versionspräfix nutzt Meta die älteste verfügbare Version. Die
    | Version ist deshalb Pflicht und pro Request überschreibbar.
    |
    */

    'version' => env('META_GRAPH_VERSION', 'v26.0'),

    /*
    | Bekannte Ablaufdaten (Y-m-d) je Version für "meta-graph:version:check".
    | Einträge hier ergänzen bzw. überschreiben die im Paket hinterlegten Werte.
    */
    'version_expirations' => [],

    'appsecret_proof' => true,

    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'upload_timeout' => 600,
    ],

    'retry' => [
        'times' => 3,
        'base_ms' => 1000,
    ],

    'rate_limit' => [
        'warn_at' => 80,
        'cache_store' => null,
    ],

    'pagination' => [
        'max_pages' => 50,
    ],

    /*
    | Planungsfenster für geplante Posts. Metas Guide nennt 10 Minuten bis
    | 30 Tage (die Video-Referenz nennt 6 Monate), daher konfigurierbar.
    */
    'scheduling' => [
        'min_lead_minutes' => 10,
        'max_lead_days' => 30,
    ],

    'tokens' => [
        'repository' => EloquentTokenRepository::class,
        'expiring_soon_days' => 7,
    ],

    'uploads' => [
        'chunk_size' => 8 * 1024 * 1024,
        'video_driver' => 'resumable', // resumable | chunked
        'session_store' => EloquentUploadSessionStore::class,
    ],

    'webhooks' => [
        'enabled' => true,
        'path' => 'meta-graph/webhook',
        'middleware' => [],
        'verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'queue' => null,
        'connection' => null,
        'dedupe_ttl' => 86400,
    ],

    'logging' => [
        'channel' => null,
        'log_requests' => false,
    ],
];
