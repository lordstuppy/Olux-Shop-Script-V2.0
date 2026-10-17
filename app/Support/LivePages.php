<?php

namespace App\Support;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Live updates: a cheap "version" of the page at a given path, for the
 * signed-in user. enhance.js polls /live and reloads the page content when
 * the version changes. Each entry checks the same access as the page itself
 * and reads only indexed columns; unknown or forbidden pages have no version
 * (they are never refreshed).
 */
final class LivePages
{
    public static function version(User $user, string $path): ?string
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return null;
        }
        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return null;
        }
        $parts = self::parts($user, (string) $route->getName(), $route->parameters());

        return $parts === null ? null : substr(hash('xxh3', json_encode($parts)), 0, 16);
    }

    /** @param  array<string, string>  $p */
    private static function parts(User $user, string $name, array $p): ?array
    {
        $max = fn (string $table, string $column, ?callable $where = null) => (string) DB::table($table)->when($where, $where)->max($column);
        $staff = $user->isStaff();

        return match ($name) {
            'orders.show', 'orders.pay', 'orders.result' => self::order($user, (string) ($p['order'] ?? '')),
            'account.orders' => [$max('orders', 'updated_at', fn ($q) => $q->where('buyer_id', $user->id)), DB::table('orders')->where('buyer_id', $user->id)->count()],
            'wallet.show' => [$user->balance_minor, $max('balance_transactions', 'id', fn ($q) => $q->where('user_id', $user->id))],
            'tickets.index' => [$max('tickets', 'updated_at', fn ($q) => $q->where('user_id', $user->id))],
            'tickets.show' => self::ticket($user, (int) ($p['ticket'] ?? 0)),
            'disputes.index' => [$max('disputes', 'updated_at', fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))],
            'disputes.show' => self::dispute($user, (int) ($p['dispute'] ?? 0)),
            'notifications.index' => [$max('user_notifications', 'id', fn ($q) => $q->where('user_id', $user->id)),
                DB::table('user_notifications')->where('user_id', $user->id)->whereNull('read_at')->count()],
            'seller.dashboard', 'seller.sales' => $user->isSeller() ? [
                $max('order_items', 'id', fn ($q) => $q->where('seller_id', $user->id)),
                $max('order_items', 'delivered_at', fn ($q) => $q->where('seller_id', $user->id)),
                $max('seller_ledger_entries', 'id', fn ($q) => $q->where('seller_id', $user->id)),
                $max('disputes', 'updated_at', fn ($q) => $q->where('seller_id', $user->id)),
            ] : null,
            'seller.payouts' => $user->isSeller() ? [$max('payouts', 'updated_at', fn ($q) => $q->where('seller_id', $user->id)),
                $max('seller_ledger_entries', 'id', fn ($q) => $q->where('seller_id', $user->id))] : null,
            // Staff decisions (approval, rejection, disabling) and stock changes from key imports.
            'seller.products.index' => $user->isSeller() ? [$max('products', 'updated_at', fn ($q) => $q->where('seller_id', $user->id)),
                DB::table('products')->where('seller_id', $user->id)->count()] : null,
            'seller.products.edit' => $user->isSeller() ? self::sellerProduct($user, (int) ($p['product'] ?? 0)) : null,
            // The application page promises to show the decision.
            'seller.apply' => [$user->role->value, $max('seller_profiles', 'updated_at', fn ($q) => $q->where('user_id', $user->id))],
            'seller.reviews' => $user->isSeller() ? [$max('product_reviews', 'updated_at', fn ($q) => $q->whereIn('product_id',
                DB::table('products')->select('id')->where('seller_id', $user->id)))] : null,
            // One statement for the eight figures (polled every 10 s per open dashboard).
            'admin.dashboard' => $staff && $user->can('staff.dashboard') ? array_values((array) DB::selectOne(<<<'SQL'
                SELECT (SELECT max(updated_at) FROM orders) AS orders, (SELECT max(updated_at) FROM disputes) AS disputes,
                       (SELECT max(updated_at) FROM payouts) AS payouts, (SELECT max(updated_at) FROM tickets) AS tickets,
                       (SELECT max(updated_at) FROM products) AS products, (SELECT max(updated_at) FROM seller_profiles) AS sellers,
                       (SELECT count(*) FROM failed_jobs) AS failed_jobs,
                       (SELECT count(*) FROM webhook_events WHERE status IN ('failed', 'dead') OR (status = 'received' AND updated_at < ?)) AS failed_webhooks
                SQL, [WebhookEvent::staleBefore()])) : null,
            'admin.orders.index' => $user->can('orders.view') ? [$max('orders', 'updated_at'), $max('order_items', 'delivered_at')] : null,
            'admin.orders.show' => $user->can('orders.view') ? self::order($user, (string) ($p['order'] ?? ''), staffView: true) : null,
            'admin.disputes.index' => $user->can('disputes.manage') ? [$max('disputes', 'updated_at')] : null,
            'admin.payouts.index' => $user->can('payouts.manage') ? [$max('payouts', 'updated_at')] : null,
            'admin.tickets.index' => $user->can('tickets.manage') ? [$max('tickets', 'updated_at')] : null,
            'admin.products.index' => $user->can('products.manage') ? [$max('products', 'updated_at')] : null,
            'admin.sellers.index' => $user->can('sellers.manage') ? [$max('seller_profiles', 'updated_at')] : null,
            'admin.reviews.index' => $user->can('reviews.moderate') ? [$max('product_reviews', 'updated_at')] : null,
            default => null,
        };
    }

    private static function order(User $user, string $publicId, bool $staffView = false): ?array
    {
        $order = Order::query()->where('public_id', $publicId)->first();
        if ($order === null || (! $staffView && ! $user->can('view', $order))) {
            return null;
        }
        $items = DB::table('order_items')->where('order_id', $order->id)
            ->selectRaw('COUNT(delivered_at) AS delivered, MAX(delivered_at) AS last_delivery, SUM(refunded_minor) AS refunded')->first();

        return [
            $order->status->value, (string) $order->updated_at, $items->delivered, (string) $items->last_delivery, $items->refunded,
            (string) DB::table('payments')->where('order_id', $order->id)->max('updated_at'),
            (string) DB::table('disputes')->where('order_id', $order->id)->max('updated_at'),
        ];
    }

    private static function sellerProduct(User $user, int $id): ?array
    {
        $product = DB::table('products')->where('id', $id)->where('seller_id', $user->id)->first(['status', 'updated_at']);
        if ($product === null) {
            return null;
        }

        return [$product->status, (string) $product->updated_at,
            DB::table('product_files')->where('product_id', $id)->max('id'),
            DB::table('product_images')->where('product_id', $id)->max('id')];
    }

    private static function ticket(User $user, int $id): ?array
    {
        $ticket = Ticket::query()->find($id);
        if ($ticket === null || ! $user->can('view', $ticket)) {
            return null;
        }

        // Internal staff notes must not change the version a customer sees, so
        // customers get only what their page shows: status and visible messages.
        $staff = $user->can('tickets.manage');

        return [$ticket->status->value, $staff ? (string) $ticket->updated_at : '',
            DB::table('ticket_messages')->where('ticket_id', $id)->when(! $staff, fn ($q) => $q->where('internal', false))->max('id')];
    }

    private static function dispute(User $user, int $id): ?array
    {
        $dispute = Dispute::query()->find($id);
        if ($dispute === null || ! $user->can('view', $dispute)) {
            return null;
        }

        $staff = $user->can('disputes.manage');

        return [$dispute->status->value, $staff ? (string) $dispute->updated_at : (string) $dispute->seller_respond_by.'|'.$dispute->refund_minor,
            DB::table('dispute_messages')->where('dispute_id', $id)->when(! $staff, fn ($q) => $q->where('internal', false))->max('id')];
    }
}
