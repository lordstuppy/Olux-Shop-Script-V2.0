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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shkeeper' => [
        // Base URL of the self-hosted Shkeeper instance, e.g. https://pay.example.com
        'base_url' => rtrim((string) env('SHKEEPER_BASE_URL', ''), '/'),
        // API key created in the Shkeeper admin UI. Sent as X-Shkeeper-Api-Key.
        'api_key' => env('SHKEEPER_API_KEY'),
        // Key used to verify the X-Shkeeper-Signature HMAC on callbacks.
        // Shkeeper signs callbacks with the API key, so set this to the same
        // value unless your Shkeeper version issues a dedicated secret.
        'webhook_secret' => env('SHKEEPER_WEBHOOK_SECRET'),
        // Maximum accepted age of X-Shkeeper-Timestamp, in seconds.
        'webhook_tolerance' => (int) env('SHKEEPER_WEBHOOK_TOLERANCE', 300),
        'timeout' => (int) env('SHKEEPER_TIMEOUT', 10),
        // Public URL Shkeeper calls back. Defaults to the webhooks.shkeeper
        // route on the current host; set it when Shkeeper reaches the shop
        // through a different hostname (for example inside Docker).
        'callback_url' => env('SHKEEPER_CALLBACK_URL'),
        // Automatic payouts and crypto refunds through Shkeeper's payout API.
        // The payout endpoints use HTTP Basic auth with a Shkeeper login.
        'payouts_enabled' => (bool) env('SHKEEPER_PAYOUTS_ENABLED', false),
        'payout_username' => env('SHKEEPER_PAYOUT_USERNAME'),
        'payout_password' => env('SHKEEPER_PAYOUT_PASSWORD'),
        // Network fee per crypto as Shkeeper expects it (BTC sat/vByte, LTC/DOGE sat/Byte, XMR 1-4), e.g. "BTC:10,LTC:10".
        'payout_fees' => collect(explode(',', (string) env('SHKEEPER_PAYOUT_FEES', '')))
            ->filter(fn ($pair) => str_contains($pair, ':'))
            ->mapWithKeys(function ($pair) {
                [$crypto, $fee] = array_map('trim', explode(':', $pair, 2));

                return [$crypto => $fee];
            })->all(),
        'payout_callback_url' => env('SHKEEPER_PAYOUT_CALLBACK_URL'),

        // Cryptocurrencies offered at checkout when the live list is unavailable.
        'fallback_cryptos' => array_filter(explode(',', (string) env('SHKEEPER_CRYPTOS', 'BTC,LTC,ETH,USDT'))),
    ],

];
