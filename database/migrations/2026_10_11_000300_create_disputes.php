<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formal disputes on a purchased order line: the buyer opens a case, the
 * seller responds (or it escalates after a deadline), staff resolve it with
 * a refund, a replacement or a rejection. One dispute per order line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 32);
            $table->string('requested_outcome', 16);
            $table->string('status', 24)->default('awaiting_seller');
            $table->timestamp('seller_respond_by');
            $table->timestamp('seller_responded_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->string('resolution', 16)->nullable();
            $table->bigInteger('refund_minor')->nullable();
            $table->foreignId('refund_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'seller_respond_by']);
            $table->index(['seller_id', 'status']);
            $table->index(['buyer_id', 'created_at']);
        });
        DB::statement("ALTER TABLE disputes ADD CONSTRAINT disputes_reason_check CHECK (reason IN ('not_delivered', 'not_working', 'not_as_described', 'other'))");
        DB::statement("ALTER TABLE disputes ADD CONSTRAINT disputes_outcome_check CHECK (requested_outcome IN ('refund', 'replacement'))");
        DB::statement("ALTER TABLE disputes ADD CONSTRAINT disputes_status_check CHECK (status IN ('awaiting_seller', 'awaiting_staff', 'resolved', 'withdrawn'))");
        DB::statement("ALTER TABLE disputes ADD CONSTRAINT disputes_resolution_check CHECK (resolution IS NULL OR resolution IN ('refund', 'replacement', 'rejected', 'withdrawn'))");

        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_role', 16);
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['dispute_id', 'id']);
        });
        DB::statement("ALTER TABLE dispute_messages ADD CONSTRAINT dispute_messages_role_check CHECK (author_role IN ('buyer', 'seller', 'staff', 'system'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');
        Schema::dropIfExists('disputes');
    }
};
