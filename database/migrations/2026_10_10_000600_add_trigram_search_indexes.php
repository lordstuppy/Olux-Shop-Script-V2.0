<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trigram GIN indexes make the catalog's ILIKE '%term%' search use an index
 * instead of scanning every product. pg_trgm is a trusted extension (owner of
 * the database may create it, PostgreSQL 13+). If it cannot be created the
 * search keeps working, only slower, and a warning is logged.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            Log::warning('pg_trgm extension unavailable; catalog search will not use trigram indexes: {reason}', ['reason' => $e->getMessage()]);
            echo "  pg_trgm unavailable, trigram indexes skipped\n";

            return;
        }
        DB::statement('CREATE INDEX IF NOT EXISTS products_title_trgm ON products USING gin (title gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS products_description_trgm ON products USING gin (description gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_title_trgm');
        DB::statement('DROP INDEX IF EXISTS products_description_trgm');
    }
};
