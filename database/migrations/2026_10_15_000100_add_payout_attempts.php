<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each Shkeeper transfer attempt of a payout gets its own external id
 * (payout-{id}, payout-{id}-a2, ...), so a late result of an earlier attempt
 * can never be applied to the attempt in flight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempt')->default(0);
        });
        // Payouts already sent used the plain id.
        DB::table('payouts')->where('provider', 'shkeeper')->update(['attempt' => 1]);
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn('attempt');
        });
    }
};
