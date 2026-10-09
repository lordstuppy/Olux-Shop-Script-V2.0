<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // When the crypto amount was quoted; stale quotes must be refreshed before paying.
            $table->timestamp('quoted_at')->nullable();
            $table->string('quote_recalculate_after', 32)->nullable();
        });

        DB::statement('ALTER TABLE balance_transactions DROP CONSTRAINT balance_transactions_type_check');
        DB::statement("ALTER TABLE balance_transactions ADD CONSTRAINT balance_transactions_type_check CHECK (type IN ('gift_card', 'refund', 'order_payment', 'late_payment_credit', 'overpayment_credit', 'admin_adjustment'))");

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->string('payout_crypto', 32)->nullable();
            // Address changes wait for confirmation from the seller's email.
            $table->string('pending_payout_address', 255)->nullable();
            $table->string('pending_payout_crypto', 32)->nullable();
            $table->char('payout_change_token_hash', 64)->nullable();
            $table->timestamp('payout_change_expires_at')->nullable();
            $table->timestamp('payout_address_changed_at')->nullable();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->string('provider', 16)->default('manual');
            $table->string('crypto', 32)->nullable();
            $table->string('crypto_amount', 64)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->string('failure_reason', 500)->nullable();
        });
        DB::statement('ALTER TABLE payouts DROP CONSTRAINT payouts_status_check');
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_status_check CHECK (status IN ('requested', 'approved', 'processing', 'paid', 'failed', 'rejected'))");
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_provider_check CHECK (provider IN ('manual', 'shkeeper'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payouts DROP CONSTRAINT payouts_provider_check');
        DB::statement('ALTER TABLE payouts DROP CONSTRAINT payouts_status_check');
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_status_check CHECK (status IN ('requested', 'approved', 'paid', 'rejected'))");
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['provider', 'crypto', 'crypto_amount', 'provider_reference', 'failure_reason']);
        });
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['payout_crypto', 'pending_payout_address', 'pending_payout_crypto', 'payout_change_token_hash', 'payout_change_expires_at', 'payout_address_changed_at']);
        });
        DB::statement('ALTER TABLE balance_transactions DROP CONSTRAINT balance_transactions_type_check');
        DB::statement("ALTER TABLE balance_transactions ADD CONSTRAINT balance_transactions_type_check CHECK (type IN ('gift_card', 'refund', 'order_payment', 'late_payment_credit', 'admin_adjustment'))");
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['quoted_at', 'quote_recalculate_after']);
        });
    }
};
