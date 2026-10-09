<?php

namespace App\Support;

/**
 * Which staff role may do what. Every ability becomes a Gate in
 * AppServiceProvider and is checked on routes with ->can() and in views
 * with @can. Admins hold every ability.
 */
final class Permissions
{
    public const MAP = [
        'staff.dashboard' => ['admin', 'finance', 'support'],
        'orders.view' => ['admin', 'finance', 'support'],
        'orders.manage' => ['admin', 'finance'],
        'orders.resend' => ['admin', 'finance', 'support'],
        'payments.view' => ['admin', 'finance'],
        'webhooks.manage' => ['admin', 'finance'],
        'payouts.manage' => ['admin', 'finance'],
        'reports.view' => ['admin', 'finance'],
        'exports.download' => ['admin', 'finance'],
        'balances.adjust' => ['admin', 'finance'],
        'coupons.manage' => ['admin', 'finance'],
        'giftcards.manage' => ['admin', 'finance'],
        'rates.manage' => ['admin', 'finance'],
        'users.view' => ['admin', 'finance', 'support'],
        'users.manage' => ['admin'],
        'sessions.revoke' => ['admin', 'support'],
        'sellers.manage' => ['admin'],
        'products.manage' => ['admin'],
        'categories.manage' => ['admin'],
        'tickets.manage' => ['admin', 'support'],
        'announcements.manage' => ['admin', 'support'],
        'reviews.moderate' => ['admin', 'support'],
        'audit.view' => ['admin'],
        'settings.manage' => ['admin'],
    ];

    /** @return list<string> */
    public static function abilitiesFor(string $role): array
    {
        return array_keys(array_filter(self::MAP, fn (array $roles) => in_array($role, $roles, true)));
    }
}
