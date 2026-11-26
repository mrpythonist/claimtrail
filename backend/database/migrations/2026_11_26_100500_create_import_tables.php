<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced import tables (v1.3.1).
 *
 * import_mappings: saved CSV column mappings, named per
 * organization, reusable across uploads, schedules, and sync
 * runs. target is 'shipments' today; claims follow later.
 *
 * import_runs: one row per import execution, whatever started
 * it (an upload confirm, a schedule, or a sync run). The
 * status and the per-outcome counts are the import history.
 *
 * import_rows: one row per CSV data row of a run, with the
 * parsed data, the validation errors, and the outcome. A
 * failed or duplicate row can be corrected with new field
 * values and retried; a corrected row points at the shipment
 * it created.
 *
 * import_schedules: a named schedule that re-runs a stored
 * file with a saved mapping. The file is one the organization
 * already uploaded; no live SFTP, URL, or provider source is
 * involved. A daily or weekly schedule advances next_run_at
 * after each run; manual schedules only run on demand.
 *
 * sync_runs: a named synchronization workflow over a saved
 * mapping and a stored file. States are pending, running,
 * completed, and failed; each run links to the import run
 * that did the work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->string('target')->default('shipments');
            $table->json('mapping');
            $table->boolean('is_default')->default(false);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'target']);
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('source')->default('upload');
            $table->uuid('import_mapping_id')->nullable();
            $table->uuid('import_schedule_id')->nullable();
            $table->uuid('sync_run_id')->nullable();
            $table->string('filename')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('import_mapping_id')->references('id')->on('import_mappings')->nullOnDelete();
            $table->foreign('import_schedule_id')->references('id')->on('import_schedules')->nullOnDelete();
            $table->foreign('sync_run_id')->references('id')->on('sync_runs')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('import_run_id');
            $table->unsignedInteger('row_index');
            $table->json('data');
            $table->string('status')->default('failed');
            $table->json('errors')->nullable();
            $table->uuid('shipment_id')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->uuid('corrected_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('import_run_id')->references('id')->on('import_runs')->cascadeOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipments')->nullOnDelete();
            $table->foreign('corrected_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['import_run_id', 'status']);
            $table->index(['import_run_id', 'row_index']);
        });

        Schema::create('import_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->uuid('import_mapping_id')->nullable();
            $table->string('source_file_path');
            $table->string('frequency')->default('manual');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->uuid('last_import_run_id')->nullable();
            $table->boolean('active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('import_mapping_id')->references('id')->on('import_mappings')->nullOnDelete();
            $table->foreign('last_import_run_id')->references('id')->on('import_runs')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'active']);
            $table->index(['active', 'next_run_at']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->uuid('import_mapping_id')->nullable();
            $table->string('source_file_path');
            $table->string('status')->default('pending');
            $table->uuid('import_run_id')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('import_mapping_id')->references('id')->on('import_mappings')->nullOnDelete();
            $table->foreign('import_run_id')->references('id')->on('import_runs')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('import_schedules');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('import_mappings');
    }
};
