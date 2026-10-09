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
    // A signed-in buyer's cart is kept this many days after its last change.
    'saved_cart_days' => (int) env('SHOP_SAVED_CART_DAYS', 30),

    // Lifetime of signed download links, in minutes.
    'download_link_ttl_minutes' => (int) env('SHOP_DOWNLOAD_TTL_MINUTES', 60 * 24 * 3),

    // Maximum upload size for product files, in kilobytes.
    'max_upload_kb' => (int) env('SHOP_MAX_UPLOAD_KB', 51200),

    'products_per_page' => 12,

    // Bearer token for /metrics. When empty, /metrics returns 404.
    'metrics_token' => env('METRICS_TOKEN'),

    // Webhook processing retries, in seconds between attempts.
    'webhook_retry_backoff' => [30, 120, 600, 1800, 7200],

    /*
    | Requests per minute (per hour for registration). Raise these only in a
    | dedicated load-test environment; the defaults are the production values.
    */
    'rate_limits' => [
        'login_per_email' => (int) env('RATE_LIMIT_LOGIN_PER_EMAIL', 5),
        'login_per_ip' => (int) env('RATE_LIMIT_LOGIN_PER_IP', 20),
        'register_per_hour' => (int) env('RATE_LIMIT_REGISTER_PER_HOUR', 10),
        'password_reset' => (int) env('RATE_LIMIT_PASSWORD_RESET', 3),
        'checkout' => (int) env('RATE_LIMIT_CHECKOUT', 10),
        'redeem' => (int) env('RATE_LIMIT_REDEEM', 5),
        'forms' => (int) env('RATE_LIMIT_FORMS', 30),
        'webhook' => (int) env('RATE_LIMIT_WEBHOOK', 120),
        'search' => (int) env('RATE_LIMIT_SEARCH', 60),
    ],

    // Comma-separated IPs/CIDRs of reverse proxies whose X-Forwarded-* headers are trusted, or "*".
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // Staff (admin, finance, support) must enable two-factor authentication before using /admin.
    'require_staff_two_factor' => (bool) env('SHOP_REQUIRE_STAFF_2FA', true),

    // Unpaid orders a buyer may have open at once; stops stock and coupon hoarding.
    'max_open_orders' => (int) env('SHOP_MAX_OPEN_ORDERS', 3),

    // Optional comma-separated IPs/CIDRs allowed to call /webhooks/shkeeper (empty = any; the signature is always required).
    'webhook_allowed_ips' => env('SHKEEPER_WEBHOOK_ALLOWED_IPS', ''),

    /*
    | Virus scanning of seller uploads with ClamAV (clamd over TCP).
    | "required": files stay undeliverable and products cannot be approved
    | until clamd reports them clean. "disabled": files are marked "skipped"
    | (development only).
    */
    'virus_scan' => env('SHOP_VIRUS_SCAN', 'required'),
    'clamav' => [
        'host' => env('CLAMAV_HOST', ''),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 60),
    ],

    // Downloads allowed per purchased item (products may override).
    'max_downloads_per_item' => (int) env('SHOP_MAX_DOWNLOADS', 10),

    // Images per product and maximum upload size per image (KB).
    'max_product_images' => 6,
    'max_image_kb' => 5120,

    // Days before a subscription ends that the renewal reminder is sent.
    'renewal_reminder_days' => (int) env('SHOP_RENEWAL_REMINDER_DAYS', 7),

    // A crypto quote older than this must be refreshed before the buyer sends funds.
    'quote_ttl_minutes' => (int) env('SHOP_QUOTE_TTL_MINUTES', 15),

    // Payouts are blocked for this long after a seller changes the payout address.
    'payout_address_cooldown_hours' => (int) env('SHOP_PAYOUT_ADDRESS_COOLDOWN_HOURS', 48),

    /*
    | Disputes: buyers can open one within this many days of delivery (or of
    | payment when nothing was delivered); sellers must answer within
    | dispute_response_days before the case escalates to staff.
    */
    /*
    | Payment methods and order limits; editable on /admin/gateway. Limits
    | are in minor units of default_currency (0 = no limit); orders in other
    | currencies are converted with the configured rate.
    */
    'payments_crypto_enabled' => (bool) env('SHOP_PAYMENTS_CRYPTO', true),
    'payments_balance_enabled' => (bool) env('SHOP_PAYMENTS_BALANCE', true),
    'crypto_disabled' => (string) env('SHOP_CRYPTO_DISABLED', ''),
    'order_min_minor' => (int) env('SHOP_ORDER_MIN_MINOR', 0),
    'order_max_minor' => (int) env('SHOP_ORDER_MAX_MINOR', 0),

    'dispute_window_days' => (int) env('SHOP_DISPUTE_WINDOW_DAYS', 14),
    'dispute_response_days' => (int) env('SHOP_DISPUTE_RESPONSE_DAYS', 3),

    'support_email' => env('SHOP_SUPPORT_EMAIL', 'support@example.com'),

    'security_email' => env('SHOP_SECURITY_EMAIL', 'security@example.com'),

    // Sent as Strict-Transport-Security when the request is HTTPS.
    'hsts_max_age' => (int) env('SHOP_HSTS_MAX_AGE', 31536000),
];
