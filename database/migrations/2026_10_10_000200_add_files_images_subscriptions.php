<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_files', function (Blueprint $table) {
            // Virus scan result; only clean (or skipped, when scanning is disabled) files are delivered.
            $table->string('scan_status', 16)->default('pending');
            $table->string('scan_detail', 255)->nullable();
            $table->timestamp('scanned_at')->nullable();
        });
        DB::statement("ALTER TABLE product_files ADD CONSTRAINT product_files_scan_check CHECK (scan_status IN ('pending', 'clean', 'infected', 'error', 'skipped'))");

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Re-encoded WebP files on the private "product_images" disk, served by ProductImageController.
            $table->string('path', 255);
            $table->string('thumb_path', 255);
            $table->integer('width');
            $table->integer('height');
            $table->string('alt_text', 160)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            // Subscription / time-limited access in days; NULL means a one-off purchase.
            $table->integer('access_days')->nullable();
            // Per-product override of the download limit per purchased item.
            $table->integer('download_limit')->nullable();
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_access_days_check CHECK (access_days IS NULL OR access_days BETWEEN 1 AND 3660)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_download_limit_check CHECK (download_limit IS NULL OR download_limit BETWEEN 1 AND 1000)');

        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('download_count')->default(0);
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('renewal_reminded_at')->nullable();
            $table->index('access_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['access_expires_at']);
            $table->dropColumn(['download_count', 'access_expires_at', 'renewal_reminded_at']);
        });
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_access_days_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_download_limit_check');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['access_days', 'download_limit']);
        });
        Schema::dropIfExists('product_images');
        DB::statement('ALTER TABLE product_files DROP CONSTRAINT product_files_scan_check');
        Schema::table('product_files', function (Blueprint $table) {
            $table->dropColumn(['scan_status', 'scan_detail', 'scanned_at']);
        });
    }
};
