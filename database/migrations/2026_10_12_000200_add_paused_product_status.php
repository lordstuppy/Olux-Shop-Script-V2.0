<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Sellers can take an approved product off sale ("paused") and put it back. */
    public function up(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_status_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'pending_review', 'active', 'disabled', 'paused'))");
    }

    public function down(): void
    {
        DB::table('products')->where('status', 'paused')->update(['status' => 'disabled']);
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_status_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'pending_review', 'active', 'disabled'))");
    }
};
