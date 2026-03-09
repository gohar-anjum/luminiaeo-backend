<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention periods (in days) per API provider / feature
    |--------------------------------------------------------------------------
    |
    | Each provider can have a global default and per-feature overrides.
    | A feature-specific value takes precedence over the provider default.
    |
    */
    'retention' => [

        'whoisxml' => [
            'default' => (int) env('API_CACHE_WHOIS_DAYS', 20),
        ],

        'safe_browsing' => [
            'default' => (int) env('API_CACHE_SAFE_BROWSING_DAYS', 30),
        ],

        'serpapi' => [
            'default' => (int) env('API_CACHE_SERP_DAYS', 30),
        ],

        'alsoasked' => [
            'default' => (int) env('API_CACHE_ALSOASKED_DAYS', 30),
        ],

        'gemini' => [
            'default' => (int) env('API_CACHE_GEMINI_DAYS', 7),
        ],

        'openai' => [
            'default' => (int) env('API_CACHE_OPENAI_DAYS', 7),
        ],

        'dataforseo' => [
            'default'          => (int) env('API_CACHE_DATAFORSEO_DAYS', 7),
            'keyword_analysis' => (int) env('API_CACHE_DATAFORSEO_KEYWORD_DAYS', 7),
            'keyword_ideas'    => (int) env('API_CACHE_DATAFORSEO_KW_IDEAS_DAYS', 7),
            'search_volume'    => (int) env('API_CACHE_DATAFORSEO_SV_DAYS', 7),
            'backlinks'        => (int) env('API_CACHE_DATAFORSEO_BACKLINKS_DAYS', 14),
            'keywords_for_site' => (int) env('API_CACHE_DATAFORSEO_KW_SITE_DAYS', 7),
        ],

        'google_keyword_planner' => [
            'default' => (int) env('API_CACHE_GKP_DAYS', 7),
        ],

        'page_analysis' => [
            'default'        => (int) env('API_CACHE_PAGE_ANALYSIS_DAYS', 7),
            'meta_optimize'  => (int) env('API_CACHE_META_OPTIMIZE_DAYS', 7),
            'semantic_score'  => (int) env('API_CACHE_SEMANTIC_SCORE_DAYS', 7),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    |
    | Large API responses are gzip-compressed before storage. Payloads smaller
    | than min_size_bytes are stored as-is to avoid overhead.
    |
    */
    'compression' => [
        'enabled'        => (bool) env('API_CACHE_COMPRESSION', true),
        'min_size_bytes' => (int) env('API_CACHE_COMPRESSION_MIN_BYTES', 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup (purge expired results)
    |--------------------------------------------------------------------------
    */
    'cleanup' => [
        'batch_size'            => (int) env('API_CACHE_CLEANUP_BATCH', 1000),
        'keep_logs_days'        => (int) env('API_CACHE_KEEP_LOGS_DAYS', 90),
        'keep_orphan_queries'   => (bool) env('API_CACHE_KEEP_ORPHAN_QUERIES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency lock
    |--------------------------------------------------------------------------
    |
    | When a cache miss occurs, the system acquires a lock so that concurrent
    | identical requests wait for the first API call to complete instead of
    | making duplicate calls.
    |
    */
    'lock' => [
        'ttl_seconds' => (int) env('API_CACHE_LOCK_TTL', 120),
        'wait_seconds' => (int) env('API_CACHE_LOCK_WAIT', 90),
    ],

];
