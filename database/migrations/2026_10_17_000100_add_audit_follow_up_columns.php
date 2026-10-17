<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the second security-audit follow-up:
 *  - exchange_rates.reference_rate/_at: the rate at the start of the current
 *    24-hour window, against which a change is bounded;
 *  - orders.paid_notice_sent_at: the "order paid" email went out (durable,
 *    replaces a cache marker);
 *  - payments.polled_at: last status poll, so the poll reaches every
 *    unresolved payment in turn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->decimal('reference_rate', 20, 10)->nullable();
            $table->timestamp('reference_at')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_notice_sent_at')->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('polled_at')->nullable();
        });
        // Orders paid before this change already had their email (or are past caring).
        DB::table('orders')->whereNotNull('paid_at')->update(['paid_notice_sent_at' => DB::raw('paid_at')]);
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('polled_at'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('paid_notice_sent_at'));
        Schema::table('exchange_rates', fn (Blueprint $table) => $table->dropColumn(['reference_rate', 'reference_at']));
    }
};
