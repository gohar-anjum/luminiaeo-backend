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
        'backlinks_limit' => env('DATAFORSEO_BACKLINKS_LIMIT', 100),
        'summary_limit' => env('DATAFORSEO_SUMMARY_LIMIT', 100),
        'citation_enabled' => env('DATAFORSEO_CITATION_ENABLED', false),
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

    'serp' => [
        'base_url' => env('SERP_API_BASE_URL', 'https://serpapi.com'),
        'api_key' => env('SERP_API_KEY'),
        'timeout' => env('SERP_API_TIMEOUT', 60),
        'cache_ttl' => env('SERP_API_CACHE_TTL', 2592000),
    ],

    'faq' => [
        'timeout' => env('FAQ_GENERATOR_TIMEOUT', 60),
        'cache_ttl' => env('FAQ_GENERATOR_CACHE_TTL', 2592000), 
    ],

    'alsoasked' => [
        'base_url' => env('ALSOASKED_BASE_URL', 'https://alsoaskedapi.com/v1'),
        'api_key' => env('ALSOASKED_API_KEY'),
        'timeout' => env('ALSOASKED_TIMEOUT', 30),
        'cache_ttl' => env('ALSOASKED_CACHE_TTL', 86400),
    ],

];
