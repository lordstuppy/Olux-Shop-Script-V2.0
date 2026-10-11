<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for foreign keys the application filters or joins on. PostgreSQL
 * does not index referencing columns by itself, so without these an order
 * page reads every order line, a ticket page every ticket message, and so on.
 * Columns that only record which staff member did something stay unindexed.
 */
return new class extends Migration
{
    private const INDEXES = [
        'order_items' => [['order_id'], ['product_id']],
        'ticket_messages' => [['ticket_id', 'id']],
        'tickets' => [['order_id']],
        'disputes' => [['order_id']],
        'product_files' => [['product_id']],
        'product_images' => [['product_id', 'position']],
        'products' => [['category_id']],
        'wishlist_items' => [['product_id']],
        'product_reviews' => [['user_id'], ['order_item_id']],
        'product_license_keys' => [['order_item_id']],
        'seller_ledger_entries' => [['payout_id'], ['order_item_id']],
        'orders' => [['coupon_id'], ['buyer_id', 'id']],
        'payments' => [['parent_payment_id']],
        'gift_cards' => [['redeemed_by']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $columns) {
                    $blueprint->index($columns);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $columns) {
                    $blueprint->dropIndex($columns);
                }
            });
        }
    }
};
