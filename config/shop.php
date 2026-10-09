<?php

return [

    /*
    | Currencies the shop accepts. Each entry maps an ISO 4217 code to the
    | number of minor-unit digits. All money is stored as integer minor units.
    | Shkeeper always supports USD and EUR as invoice fiat.
    */
    'currencies' => [
        'USD' => 2,
        'EUR' => 2,
    ],

    'default_currency' => env('SHOP_DEFAULT_CURRENCY', 'USD'),

    /*
    | Platform commission in basis points (1000 = 10.00%). A seller profile can
    | override this value.
    */
    'commission_bps' => (int) env('SHOP_COMMISSION_BPS', 1000),

    /*
    | Seller earnings become available for payout this many days after the
    | sale, which leaves room for refunds.
    */
    'payout_hold_days' => (int) env('SHOP_PAYOUT_HOLD_DAYS', 7),

    'min_payout_minor' => (int) env('SHOP_MIN_PAYOUT_MINOR', 1000),

    /*
    | Unpaid orders release their stock and coupon reservation after this
    | many minutes.
    */
    'order_ttl_minutes' => (int) env('SHOP_ORDER_TTL_MINUTES', 60),

    'max_quantity_per_line' => 10,

    'max_cart_lines' => 20,

    // Lifetime of signed download links, in minutes.
    'download_link_ttl_minutes' => (int) env('SHOP_DOWNLOAD_TTL_MINUTES', 60 * 24 * 3),

    // Maximum upload size for product files, in kilobytes.
    'max_upload_kb' => (int) env('SHOP_MAX_UPLOAD_KB', 51200),

    'products_per_page' => 12,

    // Bearer token for /metrics. When empty, /metrics returns 404.
    'metrics_token' => env('METRICS_TOKEN'),

    // Webhook processing retries, in seconds between attempts.
    'webhook_retry_backoff' => [30, 120, 600, 1800, 7200],

    'support_email' => env('SHOP_SUPPORT_EMAIL', 'support@example.com'),

    'security_email' => env('SHOP_SECURITY_EMAIL', 'security@example.com'),

    // Sent as Strict-Transport-Security when the request is HTTPS.
    'hsts_max_age' => (int) env('SHOP_HSTS_MAX_AGE', 31536000),
];
