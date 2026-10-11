<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notification centre (header badge and /notifications), the
 * per-user notification sound switch, seller replies to reviews, and indexes
 * for the live-update checks.
 */
return new class extends Migration
{
    private const LIVE_TABLES = ['orders', 'disputes', 'payouts', 'tickets', 'products', 'seller_profiles', 'product_reviews'];

    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'read_at']);
            $table->index('created_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notification_sound')->default(true);
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->text('seller_reply')->nullable();
            $table->timestamp('seller_replied_at')->nullable();
        });

        // Live updates read MAX(updated_at) of these tables (App\Support\LivePages).
        foreach (self::LIVE_TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->index('updated_at'));
        }
    }

    public function down(): void
    {
        foreach (self::LIVE_TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropIndex(['updated_at']));
        }
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropColumn(['seller_reply', 'seller_replied_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_sound');
        });
        Schema::dropIfExists('user_notifications');
    }
};
