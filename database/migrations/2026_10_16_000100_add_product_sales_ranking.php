<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units sold per product for the "best selling" sort and related products,
 * refreshed by shop:refresh-sales-ranking (CatalogService::refreshSalesRanking).
 * Summing order_items for every listed product on every catalogue view took
 * about 0.8 s at 20,000 products and 250,000 order items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('units_sold')->default(0);
            $table->index(['status', 'units_sold', 'id']);
        });
        DB::statement(<<<'SQL'
            UPDATE products SET units_sold = s.units
            FROM (SELECT order_items.product_id, SUM(order_items.quantity) AS units FROM order_items
                  JOIN orders ON orders.id = order_items.order_id
                  WHERE orders.status IN ('paid', 'delivered', 'partially_refunded')
                  GROUP BY order_items.product_id) s
            WHERE s.product_id = products.id
            SQL);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['status', 'units_sold', 'id']);
            $table->dropColumn('units_sold');
        });
    }
};
