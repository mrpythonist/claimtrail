<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reliability indexes (v1.1.1, database optimization).
 *
 * - jobs: the database queue worker polls by queue, available_at,
 *   and reserved_at; the composite index serves that query.
 * - shipments: the v1.1.0 delivery date range filter scans by
 *   organization and delivery appointment.
 * - claims: the v1.1.0 requested-amount range filter scans by
 *   organization and requested amount.
 *
 * The rest of the hot paths were already covered by the Phase 1
 * migrations (organization + status, organization + shipment,
 * organization + category, and the notification/audit indexes).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->index(['queue', 'available_at', 'reserved_at']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['organization_id', 'delivery_appointment']);
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->index(['organization_id', 'requested_amount']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'requested_amount']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'delivery_appointment']);
        });

        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex(['queue', 'available_at', 'reserved_at']);
        });
    }
};
