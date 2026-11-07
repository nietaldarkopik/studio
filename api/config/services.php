<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

    'nine_router' => [
        'base_url' => env('NINE_ROUTER_BASE_URL', 'http://localhost:20128/v1'),
        'api_key' => env('NINE_ROUTER_API_KEY'),
        'api_key_file' => env('NINE_ROUTER_API_KEY_FILE', '../9router-key.txt'),
        'model' => env('NINE_ROUTER_MODEL', 'Kombo'),
        'timeout' => env('NINE_ROUTER_TIMEOUT', 600),
    ],

    'tavily' => [
        'base_url' => env('TAVILY_BASE_URL', 'https://api.tavily.com'),
        'api_key' => env('TAVILY_API_KEY'),
        'timeout' => env('TAVILY_TIMEOUT', 30),
        'max_results' => env('TAVILY_MAX_RESULTS', 6),
        'search_depth' => env('TAVILY_SEARCH_DEPTH', 'basic'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
