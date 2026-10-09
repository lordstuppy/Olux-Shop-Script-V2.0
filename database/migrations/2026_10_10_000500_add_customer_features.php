<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Email changes take effect only after the new address confirms.
            $table->string('pending_email')->nullable();
            $table->char('email_change_token_hash', 64)->nullable();
            $table->timestamp('email_change_expires_at')->nullable();
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->smallInteger('rating');
            $table->string('title', 120);
            $table->text('body');
            $table->string('status', 16)->default('visible');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'user_id']);
            $table->index(['product_id', 'status']);
        });
        DB::statement('ALTER TABLE product_reviews ADD CONSTRAINT product_reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE product_reviews ADD CONSTRAINT product_reviews_status_check CHECK (status IN ('visible', 'hidden'))");

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('product_reviews');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'email_change_token_hash', 'email_change_expires_at']);
        });
    }
};
