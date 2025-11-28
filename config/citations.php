<?php

return [
    'default_queries' => (int) env('CITATION_DEFAULT_QUERIES', 10),
    'max_queries' => (int) env('CITATION_MAX_QUERIES_PER_TASK', 150),
    'chunk_size' => (int) env('CITATION_CHUNK_SIZE', 10),
    'chunk_delay_seconds' => (int) env('CITATION_CHUNK_DELAY', 0),
    'openai' => [
        'model' => env('CITATION_OPENAI_MODEL', 'gpt-4o'),
        'timeout' => (int) env('CITATION_OPENAI_TIMEOUT', 60),
        'max_retries' => (int) env('CITATION_OPENAI_RETRIES', 3),
        'backoff_seconds' => (int) env('CITATION_OPENAI_BACKOFF', 2),
        'circuit_breaker' => (int) env('CITATION_OPENAI_CIRCUIT_BREAKER', 5),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
    ],
    'gemini' => [
        'api' => env('GOOGLE_API_KEY'),
        'model' => env('CITATION_GEMINI_MODEL', 'gemini-2.0-pro-exp-02-05'),
        'timeout' => (int) env('CITATION_GEMINI_TIMEOUT', 60),
        'max_retries' => (int) env('CITATION_GEMINI_RETRIES', 3),
        'backoff_seconds' => (int) env('CITATION_GEMINI_BACKOFF', 2),
        'circuit_breaker' => (int) env('CITATION_GEMINI_CIRCUIT_BREAKER', 5),
    ],
    'stream' => [
        'retry_ms' => (int) env('CITATION_STREAM_RETRY_MS', 1000),
        'heartbeat_seconds' => (int) env('CITATION_STREAM_HEARTBEAT', 10),
    ],
    'cache_days' => (int) env('CITATION_CACHE_DAYS', 30),
];

