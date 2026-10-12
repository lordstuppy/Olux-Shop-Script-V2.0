<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue's default order (newest first) on listed products: without
 * it every catalogue and home page sorted all listed products (34 ms at
 * 17,000; 0.2 ms with it, docs/performance/database-performance-audit.md).
 * Built CONCURRENTLY so a live shop keeps working while it builds.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_status_created_at_id_index ON products (status, created_at, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_status_created_at_id_index');
    }
};
