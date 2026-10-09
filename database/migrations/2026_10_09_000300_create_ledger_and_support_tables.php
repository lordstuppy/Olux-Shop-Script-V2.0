<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only record of every change to users.balance_minor.
        Schema::create('balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('type', 32);
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->bigInteger('balance_after_minor');
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
        DB::statement("ALTER TABLE balance_transactions ADD CONSTRAINT balance_transactions_type_check CHECK (type IN ('gift_card', 'refund', 'order_payment', 'late_payment_credit', 'admin_adjustment'))");
        DB::statement('ALTER TABLE balance_transactions ADD CONSTRAINT balance_transactions_after_check CHECK (balance_after_minor >= 0 AND amount_minor <> 0)');

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            // HMAC-SHA256 of the normalised code; the plain code is shown once at creation.
            $table->char('code_hash', 64)->unique();
            $table->char('code_last4', 4);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE gift_cards ADD CONSTRAINT gift_cards_amount_check CHECK (amount_minor > 0)');

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('destination', 255);
            $table->string('status', 16)->default('requested');
            $table->string('reference', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_status_check CHECK (status IN ('requested', 'approved', 'paid', 'rejected'))");
        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_check CHECK (amount_minor > 0)');

        // Seller earnings ledger. The sum per seller and currency is the seller balance.
        Schema::create('seller_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained('payouts')->restrictOnDelete();
            $table->string('type', 24);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['seller_id', 'currency', 'created_at']);
        });
        DB::statement("ALTER TABLE seller_ledger_entries ADD CONSTRAINT seller_ledger_type_check CHECK (type IN ('sale', 'commission', 'refund_adjustment', 'payout', 'payout_reversal'))");

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('subject', 160);
            $table->string('category', 16)->default('support');
            $table->string('status', 16)->default('open');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('open', 'answered', 'closed'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_category_check CHECK (category IN ('support', 'order_issue', 'seller', 'billing'))");

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('actor_id');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            // SHA-256 of the raw body; identical redeliveries collapse onto one row.
            $table->char('event_key', 64);
            $table->string('external_id', 64)->nullable();
            $table->jsonb('payload');
            $table->string('status', 16)->default('received');
            $table->integer('attempts')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_key']);
            $table->index(['status', 'next_attempt_at']);
        });
        DB::statement("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_status_check CHECK (status IN ('received', 'processed', 'ignored', 'rejected', 'failed', 'dead'))");

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base', 3);
            $table->char('quote', 3);
            // 1 unit of base = rate units of quote.
            $table->decimal('rate', 20, 10);
            $table->string('source', 64)->default('manual');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['base', 'quote']);
        });
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_check CHECK (rate > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('seller_ledger_entries');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('balance_transactions');
    }
};
