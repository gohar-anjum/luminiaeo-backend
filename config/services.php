<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'google' => [
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN'),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        'redirect_uri' => env('GOOGLE_ADS_REDIRECT_URI'),
    ],

    'dataforseo' => [
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com/v3'),
        'login'    => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'timeout'  => env('DATAFORSEO_TIMEOUT', 60),
        'cache_ttl' => env('DATAFORSEO_CACHE_TTL', 86400),
        'max_concurrent_requests' => env('DATAFORSEO_MAX_CONCURRENT_REQUESTS', 5),
        // Search Volume API limits
        'search_volume' => [
            'max_keywords' => env('DATAFORSEO_SEARCH_VOLUME_MAX_KEYWORDS', 100),
            'batch_size' => env('DATAFORSEO_SEARCH_VOLUME_BATCH_SIZE', 100),
        ],
        // Backlinks API limits
        'backlinks' => [
            'default_limit' => env('DATAFORSEO_BACKLINKS_DEFAULT_LIMIT', 100),
            'max_limit' => env('DATAFORSEO_BACKLINKS_MAX_LIMIT', 1000),
            'summary_limit' => env('DATAFORSEO_BACKLINKS_SUMMARY_LIMIT', 100),
        ],
        // Keywords for Site API limits
        'keywords_for_site' => [
            'max_limit' => env('DATAFORSEO_KEYWORDS_FOR_SITE_MAX_LIMIT', 1000),
            'default_limit' => env('DATAFORSEO_KEYWORDS_FOR_SITE_DEFAULT_LIMIT', 100),
        ],
        // Keyword Ideas API limits
        'keyword_ideas' => [
            'max_limit' => env('DATAFORSEO_KEYWORD_IDEAS_MAX_LIMIT', 1000),
            'default_limit' => env('DATAFORSEO_KEYWORD_IDEAS_DEFAULT_LIMIT', 100),
        ],
        // Legacy support (deprecated, use nested configs above)
        'backlinks_limit' => env('DATAFORSEO_BACKLINKS_LIMIT', 100),
        'summary_limit' => env('DATAFORSEO_SUMMARY_LIMIT', 100),
        'keyword_planner_enabled' => env('DATAFORSEO_KEYWORD_PLANNER_ENABLED', false),
    ],

    'whoisxml' => [
        'base_url' => env('WHOISXML_BASE_URL', 'https://www.whoisxmlapi.com'),
        'api_key' => env('WHOISXML_API_KEY'),
        'timeout' => env('WHOISXML_TIMEOUT', 20),
        'cache_ttl' => env('WHOISXML_CACHE_TTL', 604800),
    ],

    'pbn_detector' => [
        'base_url' => env('PBN_DETECTOR_URL'),
        'timeout' => env('PBN_DETECTOR_TIMEOUT', 30),
        'cache_ttl' => env('PBN_DETECTOR_CACHE_TTL', 86400),
        'secret' => env('PBN_DETECTOR_SECRET'),
    ],

    'safe_browsing' => [
        'base_url' => env('SAFE_BROWSING_BASE_URL', 'https://safebrowsing.googleapis.com/v4'),
        'api_key' => env('SAFE_BROWSING_API_KEY'),
        'timeout' => env('SAFE_BROWSING_TIMEOUT', 15),
        'cache_ttl' => env('SAFE_BROWSING_CACHE_TTL', 604800),
    ],

    // Answer The Public service is disabled - commented out as it's no longer needed
    /*
    'answerthepublic' => [
        'api_key' => env('ANSWERTHEPUBLIC_API_KEY'),
        'timeout' => env('ANSWERTHEPUBLIC_TIMEOUT', 60),
    ],
    */

    'keyword_clustering' => [
        'url' => env('KEYWORD_CLUSTERING_SERVICE_URL'),
        'timeout' => env('KEYWORD_CLUSTERING_TIMEOUT', 120),
    ],

    'keyword_intent' => [
        'url' => env('KEYWORD_INTENT_SERVICE_URL', 'http://localhost:8002'),
        'timeout' => env('KEYWORD_INTENT_SERVICE_TIMEOUT', 60),
    ],

    'serp' => [
        'base_url' => env('SERP_API_BASE_URL', 'https://serpapi.com'),
        'api_key' => env('SERP_API_KEY'),
        'timeout' => env('SERP_API_TIMEOUT', 60),
        'cache_ttl' => env('SERP_API_CACHE_TTL', 2592000),
    ],

    'faq' => [
        'timeout' => env('FAQ_GENERATOR_TIMEOUT', 60),
        'cache_ttl' => env('FAQ_GENERATOR_CACHE_TTL', 2592000),
        'default_language' => env('FAQ_DEFAULT_LANGUAGE', 'en'),
        'default_location' => env('FAQ_DEFAULT_LOCATION', 2840),
    ],

    'citations' => [
        'default_location_code' => env('CITATIONS_DEFAULT_LOCATION_CODE', 2840),
        'default_language_code' => env('CITATIONS_DEFAULT_LANGUAGE_CODE', 'en'),
    ],

    'alsoasked' => [
        'base_url' => env('ALSOASKED_BASE_URL', 'https://alsoaskedapi.com/v1'),
        'api_key' => env('ALSOASKED_API_KEY'),
        'timeout' => env('ALSOASKED_TIMEOUT', 30),
        'cache_ttl' => env('ALSOASKED_CACHE_TTL', 86400),
    ],

];
