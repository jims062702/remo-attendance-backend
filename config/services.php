<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Sign-In
    |--------------------------------------------------------------------------
    |
    | The only way into this application. Create an OAuth 2.0 Client ID of type
    | "Web application" in Google Cloud Console and register the redirect URI
    | below under "Authorised redirect URIs" -- it must match character for
    | character or Google refuses the request.
    |
    | Signing in never creates an account: the Google address must already
    | belong to a user an administrator added. See GoogleAuthService.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),

        // Optional belt-and-braces restriction. When set, a Google account
        // whose email is outside these domains is refused even if a matching
        // user record somehow exists. Comma separated, e.g. "acme.com,acme.ph".
        'allowed_domains' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('GOOGLE_ALLOWED_DOMAINS', ''))),
        )),
    ],

];
