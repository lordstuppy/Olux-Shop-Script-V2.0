<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The cart of a signed-in buyer, so it survives logout, session expiry
     * and a change of device. Only product ids and quantities are kept;
     * prices are always recalculated from the catalog.
     */
    public function up(): void
    {
        Schema::create('saved_carts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->jsonb('items');
            $table->char('currency', 3)->nullable();
            $table->timestamp('updated_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_carts');
    }
};
