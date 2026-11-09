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
        'api_key_file' => env('NINE_ROUTER_API_KEY_FILE', '../../9router-key.txt'),
        'model' => env('NINE_ROUTER_MODEL', 'Kombo'),
        'timeout' => env('NINE_ROUTER_TIMEOUT', 600),
        'rate_limit_retries' => (int) env('NINE_ROUTER_RATE_LIMIT_RETRIES', 3),
    ],

    'tavily' => [
        'base_url' => env('TAVILY_BASE_URL', 'https://api.tavily.com'),
        'api_key' => env('TAVILY_API_KEY'),
        'timeout' => env('TAVILY_TIMEOUT', 30),
        'max_results' => env('TAVILY_MAX_RESULTS', 6),
        'search_depth' => env('TAVILY_SEARCH_DEPTH', 'basic'),
        // Tanpa API key, pakai pencarian cadangan (hanya Wikipedia).
        'keyless_fallback' => filter_var(env('SEARCH_KEYLESS_FALLBACK', true), FILTER_VALIDATE_BOOL),
    ],

    'search' => [
        // Bila TAVILY_API_KEY kosong, cari lewat 9Router /v1/search (penyedia terkonfigurasi di sana).
        'via_nine_router' => filter_var(env('SEARCH_VIA_NINE_ROUTER', true), FILTER_VALIDATE_BOOL),
        'nine_router_providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('SEARCH_NINE_ROUTER_PROVIDERS', 'tavily,exa'))))),
    ],

    'sketchfab' => [
        // Token API Sketchfab (akun gratis). Kosong = model 3D Sketchfab tidak dipakai.
        'token' => env('SKETCHFAB_API_TOKEN'),
        // Lisensi yang boleh dipakai. CC-BY wajib atribusi (otomatis ditulis di deskripsi video).
        'allowed_licenses' => env('SKETCHFAB_ALLOWED_LICENSES', 'cc0,by'),
        'max_faces' => (int) env('SKETCHFAB_MAX_FACES', 120000),
        'max_download_mb' => (int) env('SKETCHFAB_MAX_DOWNLOAD_MB', 80),
    ],

    'polypizza' => [
        // Key API Poly Pizza (poly.pizza/settings/api). Kosong = tidak dipakai.
        'key' => env('POLYPIZZA_API_KEY'),
        'allowed_licenses' => env('POLYPIZZA_ALLOWED_LICENSES', 'cc0,by'),
        'max_download_mb' => (int) env('POLYPIZZA_MAX_DOWNLOAD_MB', 40),
    ],

    'clipwize' => [
        // Alamat dicoba berurutan; backend dan token sama untuk semuanya.
        'bases' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'CLIPWIZE_BASES',
            'https://existcode.excoder.site/api,http://localhost:8000/api',
        ))))),
        // Token dibuat pengguna lewat `clipwize.py login`; hanya dibaca server-side.
        'token' => env('CLIPWIZE_TOKEN'),
        'token_file' => env('CLIPWIZE_TOKEN_FILE', 'C:\\Videos\\FaktaUnik\\.clipwize_token'),
        'account_id' => env('CLIPWIZE_ACCOUNT_ID'),
        'account_name' => env('CLIPWIZE_ACCOUNT_NAME', 'Ternyata Begini'),
        'timeout' => env('CLIPWIZE_TIMEOUT', 180),
    ],

    'production' => [
        'storage_root' => env('PRODUCTION_STORAGE_ROOT'),
        // Urutan sumber model 3D (yang tidak dikonfigurasi dilewati). Poly Pizza dulu: gayanya low-poly seperti visual kita.
        'model_sources' => env('PRODUCTION_MODEL_SOURCES', 'polypizza,sketchfab'),
        'blender_path' => env('BLENDER_PATH', 'C:\\Program Files\\Blender Foundation\\Blender 5.2\\blender.exe'),
        'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),
        'font_file' => env('PRODUCTION_FONT_FILE', 'C:\\Windows\\Fonts\\segoeuib.ttf'),
        'logo_file' => env('PRODUCTION_LOGO_FILE', '../branding/logo.png'),
        'bgm_file' => env('PRODUCTION_BGM_FILE'),
        'used_topics_file' => env('PRODUCTION_USED_TOPICS_FILE', 'C:\\Videos\\FaktaUnik\\topik_terpakai.txt'),
        'render_width' => (int) env('PRODUCTION_RENDER_WIDTH', 540),
        'render_height' => (int) env('PRODUCTION_RENDER_HEIGHT', 960),
        'render_fps' => (int) env('PRODUCTION_RENDER_FPS', 10),
        'render_samples' => (int) env('PRODUCTION_RENDER_SAMPLES', 8),
        'render_timeout' => (int) env('PRODUCTION_RENDER_TIMEOUT', 14400),
        'scene_gap_seconds' => (float) env('PRODUCTION_SCENE_GAP', 0.35),
        'min_duration' => (float) env('PRODUCTION_MIN_DURATION', 20),
        'max_duration' => (float) env('PRODUCTION_MAX_DURATION', 180),
        // Publikasi otomatis hanya jika true DAN QC lolos. Default aman: butuh persetujuan.
        'auto_publish' => filter_var(env('PRODUCTION_AUTO_PUBLISH', false), FILTER_VALIDATE_BOOL),
        'publish_poll_interval' => (int) env('PRODUCTION_PUBLISH_POLL_INTERVAL', 15),
        'publish_poll_attempts' => (int) env('PRODUCTION_PUBLISH_POLL_ATTEMPTS', 40),
        // false = riset & verifikasi sumber opsional (tidak memblokir produksi).
        'require_research' => filter_var(env('PRODUCTION_REQUIRE_RESEARCH', false), FILTER_VALIDATE_BOOL),
        'max_posts_per_day' => (int) env('PRODUCTION_MAX_POSTS_PER_DAY', 1),
        'daily_at' => env('PRODUCTION_DAILY_AT'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
