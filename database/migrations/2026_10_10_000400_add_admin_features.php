<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('ticket_messages', function (Blueprint $table) {
            // Internal notes are visible to staff only.
            $table->boolean('internal')->default(false);
        });

        // Admin-editable overrides of selected config('shop.*') values.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('value', 255);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('body');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->integer('max_per_user')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('max_per_user'));
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('settings');
        Schema::table('ticket_messages', fn (Blueprint $table) => $table->dropColumn('internal'));
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
