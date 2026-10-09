<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened between the shop and the payment gateway: incoming
 * callbacks (accepted, duplicate, rejected signature or address) and
 * outgoing API calls (ok, error, timeout). Kept for 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_logs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32)->default('shkeeper');
            $table->string('channel', 24);
            $table->string('outcome', 32);
            $table->string('action', 128)->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('external_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at']);
            $table->index(['channel', 'outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_logs');
    }
};
