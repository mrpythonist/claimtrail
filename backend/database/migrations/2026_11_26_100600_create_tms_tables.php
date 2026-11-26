<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TMS integration tables (v1.3.2).
 *
 * tms_integrations: one row per TMS connection for an
 * organization. provider is the driver name ('mock' or
 * 'trimble-tmw'); credentials are stored encrypted and hold
 * the provider's auth material (client id and secret for
 * Trimble, empty for the mock); settings is the sync
 * selection (which kinds sync, whether auto-sync runs, the
 * interval). status is active or disabled; a disabled
 * integration never syncs. last_error carries the latest
 * failure message for the monitoring view.
 *
 * tms_sync_runs: one row per sync execution, whatever
 * started it (an API run, the tms:sync command, or the
 * tms:run-due scheduler). kind is shipments, status,
 * appointments, documents, or full. summary carries the
 * per-outcome counts; error_message the failure reason.
 * A run over a schema-only provider fails with the live
 * unavailable message, which is the honest record of
 * what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tms_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('provider');
            $table->string('name');
            $table->string('status')->default('active');
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'provider']);
        });

        Schema::create('tms_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('tms_integration_id');
            $table->string('kind')->default('full');
            $table->string('status')->default('pending');
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('tms_integration_id')->references('id')->on('tms_integrations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'tms_integration_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tms_sync_runs');
        Schema::dropIfExists('tms_integrations');
    }
};
