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
            // Encrypted TOTP secret and recovery codes (app key).
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // Last accepted TOTP time step; a code can be used only once.
            $table->bigInteger('two_factor_last_step')->nullable();
        });

        // Staff roles with narrower permissions than admin.
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('buyer', 'seller', 'support', 'finance', 'admin'))");

        // Known devices per user, for new-device login alerts.
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->string('user_agent', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('buyer', 'seller', 'admin'))");
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']);
        });
    }
};
