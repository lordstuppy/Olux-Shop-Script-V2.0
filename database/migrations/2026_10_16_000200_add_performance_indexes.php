<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for queries whose plans scanned large parts of a table at 200,000
 * orders and 250,000 order lines (docs/performance/database-performance-audit.md):
 *
 * - orders (paid_at), orders (created_at): dashboard and report periods, and
 *   shop:recover-stuck-orders every minute (were sequential scans).
 * - order_items (seller_id, id): the seller's latest sales and their live
 *   version (was a backward scan of all order lines).
 * - order_items (delivered_at): latest delivery for the admin order list's
 *   live version (was a sequential scan).
 *
 * Built CONCURRENTLY so a live shop keeps taking orders while they build.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'orders_paid_at_index' => 'orders (paid_at)',
        'orders_created_at_index' => 'orders (created_at)',
        'order_items_seller_id_id_index' => 'order_items (seller_id, id)',
        'order_items_delivered_at_index' => 'order_items (delivered_at)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $on) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$on}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
