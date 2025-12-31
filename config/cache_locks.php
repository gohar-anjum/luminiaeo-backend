<?php

return [
    'keyword_research' => [
        'timeout' => env('CACHE_LOCK_KEYWORD_RESEARCH_TIMEOUT', 10),
    ],
    'search_volume' => [
        'timeout' => env('CACHE_LOCK_SEARCH_VOLUME_TIMEOUT', 30),
    ],
    'citations' => [
        'timeout' => env('CACHE_LOCK_CITATIONS_TIMEOUT', 60),
    ],
    'faq' => [
        'timeout' => env('CACHE_LOCK_FAQ_TIMEOUT', 120),
    ],
];

