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

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
        'http_client' => env('BREVO_HTTP_CLIENT', 'auto'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_calendar' => [
        'credentials_path' => storage_path(env('GOOGLE_CALENDAR_CREDENTIALS_PATH', 'app/private/google-calendar-service-account.json')),
        'calendar_id' => env('GOOGLE_CALENDAR_ID'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // Lightest/fastest model first — confirmed via a live log that
        // gemini-3.6-flash and gemini-3.8-flash were both overloaded
        // (503 / silent timeout) while gemini-3.5-flash-lite answered in
        // 4-6s, so put the model that's actually responding first and the
        // heavier ones behind it as fallbacks. See ResumeParsingService.
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        // Tried once each, in order, after the primary model's own retries
        // are still busy (429/503/timeout).
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.6-flash'),
        'fallback_model_2' => env('GEMINI_FALLBACK_MODEL_2', 'gemini-3.8-flash'),
        // Per-attempt HTTP timeout and the total budget across the whole
        // chain (all attempts + backoff waits) — see
        // ResumeParsingService::parse(). Once the budget is spent the AI
        // chain stops and the local text fallback runs instead of starting
        // another attempt that wouldn't finish in time anyway.
        'attempt_timeout' => (int) env('GEMINI_ATTEMPT_TIMEOUT', 15),
        'total_budget' => (int) env('GEMINI_TOTAL_BUDGET', 35),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-2'),
    ],

    'jaas' => [
        'app_id' => env('JAAS_APP_ID'),
        'key_id' => env('JAAS_KEY_ID'),
        'private_key_path' => storage_path(env('JAAS_PRIVATE_KEY_PATH', 'app/private/jaas-private-key.pem')),
    ],

];
