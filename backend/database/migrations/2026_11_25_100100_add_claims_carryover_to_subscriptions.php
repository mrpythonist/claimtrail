<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claims carried over from the previous period when a move to a
 * lower plan takes effect (v1.2.1).
 *
 * A downgrade must block new claims above the lower limit
 * (v0.5.0 section 9.2), but the renewal starts a fresh billing
 * period, so claims created in the old period would stop counting
 * the moment the downgrade applies. The carryover keeps them
 * counting for the new period only: the next renewal clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('claims_carryover')->default(0)->after('credit_balance_cents');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('claims_carryover');
        });
    }
};
