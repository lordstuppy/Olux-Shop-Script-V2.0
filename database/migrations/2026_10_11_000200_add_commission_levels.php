<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commission can be set per product, per seller (seller_profiles.commission_bps,
 * already present), per category, or globally (setting commission_bps). The
 * most specific level wins; the result is frozen on each order line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->integer('commission_bps')->nullable();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->integer('commission_bps')->nullable();
        });
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_commission_check CHECK (commission_bps IS NULL OR commission_bps BETWEEN 0 AND 10000)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_commission_check CHECK (commission_bps IS NULL OR commission_bps BETWEEN 0 AND 10000)');
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('commission_bps'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('commission_bps'));
    }
};
