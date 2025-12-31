<?php

return [
    'default_queries' => (int) env('CITATION_DEFAULT_QUERIES', 5000),
    'max_queries' => (int) env('CITATION_MAX_QUERIES_PER_TASK', env('DATAFORSEO_CITATION_MAX_QUERIES', 5000)),
    'chunk_size' => (int) env('CITATION_CHUNK_SIZE', env('DATAFORSEO_CITATION_CHUNK_SIZE', 25)),
    'chunk_delay_seconds' => (int) env('CITATION_CHUNK_DELAY', 0),
    'query_generation' => [
        'max_per_call' => (int) env('CITATION_QUERY_GENERATION_BATCH', 250),
    ],
    'validation' => [
        'batch_size' => (int) env('CITATION_VALIDATION_BATCH', 25),
    ],
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
    'dataforseo' => [
        'enabled' => env('DATAFORSEO_CITATION_ENABLED', false),
        'llm_mentions_enabled' => env('DATAFORSEO_LLM_MENTIONS_ENABLED', false),
        'llm_mentions_platform' => env('DATAFORSEO_LLM_MENTIONS_PLATFORM', 'google'), // 'google' or 'chat_gpt'
        'llm_mentions_limit' => (int) env('DATAFORSEO_LLM_MENTIONS_LIMIT', 100),
    ],
];
