<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('display_name', 80);
            $table->char('payout_currency', 3);
            $table->string('payout_address', 255);
            $table->text('about')->nullable();
            $table->string('status', 16)->default('pending');
            $table->integer('commission_bps')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE seller_profiles ADD CONSTRAINT seller_profiles_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE seller_profiles ADD CONSTRAINT seller_profiles_commission_check CHECK (commission_bps IS NULL OR (commission_bps BETWEEN 0 AND 10000))');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('title', 160);
            $table->text('description');
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            // NULL means unlimited stock (for example a downloadable tutorial).
            $table->integer('stock')->nullable();
            $table->string('delivery_type', 16)->default('instant');
            $table->string('status', 16)->default('draft');
            $table->timestamps();

            $table->index(['status', 'category_id']);
            $table->index('seller_id');
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_check CHECK (price_minor > 0 AND price_minor <= 99999999)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_check CHECK (stock IS NULL OR stock >= 0)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_delivery_check CHECK (delivery_type IN ('instant', 'manual'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'pending_review', 'active', 'disabled'))");

        Schema::create('product_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_path', 255);
            $table->char('checksum', 64);
            $table->bigInteger('size');
            // Retired files are no longer delivered to new orders but stay downloadable for past buyers.
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_license_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Encrypted with the application key.
            $table->text('key_encrypted');
            $table->char('key_fingerprint', 64);
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'key_fingerprint']);
            $table->index(['product_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_license_keys');
        Schema::dropIfExists('product_files');
        Schema::dropIfExists('products');
        Schema::dropIfExists('seller_profiles');
        Schema::dropIfExists('categories');
    }
};
