<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Why staff disabled or did not approve a product; shown to the seller. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('moderation_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('moderation_note');
        });
    }
};
