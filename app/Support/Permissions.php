<?php

namespace App\Support;

/**
 * Which staff role may do what. Every ability becomes a Gate in
 * AppServiceProvider and is checked on routes with ->can() and in views
 * with @can.
 *
 * Tiers:
 *  - admin (Super admin): everything, including financial settings, the
 *    payment gateway, commission rates, roles and email templates.
 *  - manager: day-to-day operations (orders, refunds, products, sellers,
 *    users, payouts, disputes, reports); no financial settings or roles.
 *  - finance: money (payments, refunds, payouts, coupons, gift cards,
 *    exchange rates, reports, exports, balance adjustments).
 *  - moderator: content (product review, categories, reviews), disputes,
 *    tickets and announcements.
 *  - support: tickets, read-only orders and users, session sign-out.
 */
final class Permissions
{
    public const MAP = [
        'staff.dashboard' => ['admin', 'manager', 'finance', 'moderator', 'support'],
        'analytics.view' => ['admin', 'manager', 'finance'],
        'orders.view' => ['admin', 'manager', 'finance', 'moderator', 'support'],
        'orders.manage' => ['admin', 'manager', 'finance'],
        'orders.resend' => ['admin', 'manager', 'finance', 'support'],
        'orders.export' => ['admin', 'manager', 'finance'],
        'payments.view' => ['admin', 'manager', 'finance'],
        'webhooks.manage' => ['admin', 'manager', 'finance'],
        'gateway.view' => ['admin', 'manager', 'finance'],
        'gateway.manage' => ['admin'],
        'payouts.manage' => ['admin', 'manager', 'finance'],
        'reports.view' => ['admin', 'manager', 'finance'],
        'exports.download' => ['admin', 'manager', 'finance'],
        'balances.adjust' => ['admin', 'finance'],
        'coupons.manage' => ['admin', 'finance'],
        'giftcards.manage' => ['admin', 'finance'],
        'rates.manage' => ['admin', 'finance'],
        'commission.manage' => ['admin'],
        'users.view' => ['admin', 'manager', 'finance', 'moderator', 'support'],
        'users.manage' => ['admin', 'manager'],
        'roles.manage' => ['admin'],
        'sessions.revoke' => ['admin', 'manager', 'support'],
        'sellers.manage' => ['admin', 'manager'],
        'products.manage' => ['admin', 'manager', 'moderator'],
        'categories.manage' => ['admin', 'manager', 'moderator'],
        'disputes.manage' => ['admin', 'manager', 'moderator'],
        'tickets.manage' => ['admin', 'manager', 'moderator', 'support'],
        'announcements.manage' => ['admin', 'manager', 'moderator', 'support'],
        'reviews.moderate' => ['admin', 'manager', 'moderator', 'support'],
        'templates.manage' => ['admin', 'manager'],
        'audit.view' => ['admin', 'manager'],
        'health.view' => ['admin', 'manager', 'finance'],
        'settings.manage' => ['admin'],
    ];

    /** @return list<string> */
    public static function abilitiesFor(string $role): array
    {
        return array_keys(array_filter(self::MAP, fn (array $roles) => in_array($role, $roles, true)));
    }

    /** @return list<string> */
    public static function rolesFor(string $ability): array
    {
        return self::MAP[$ability] ?? [];
    }
}
