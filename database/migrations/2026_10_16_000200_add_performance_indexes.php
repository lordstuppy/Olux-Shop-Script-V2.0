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

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_paid_at_index ON orders (paid_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_created_at_index ON orders (created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS order_items_seller_id_id_index ON order_items (seller_id, id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS order_items_delivered_at_index ON order_items (delivered_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_paid_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS order_items_seller_id_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS order_items_delivered_at_index');
    }
};
