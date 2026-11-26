<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production monitoring check history (v1.2.3).
 *
 * One row per run of `php artisan monitoring:check` (hourly by
 * schedule, or on demand from the operations dashboard). The row
 * stores the rolled-up status plus the per-check results, so the
 * operations page can show the latest state and the recent
 * history without re-running anything. A failing check does not
 * page anyone yet: there is no alerting channel configured, so a
 * failure is visible on the operations dashboard and in the log,
 * and the runbook in docs/OPERATIONS.md says what to do next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 50);
            $table->json('checks');
            $table->unsignedInteger('failed_jobs')->default(0);
            $table->unsignedInteger('queue_depth')->default(0);
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_checks');
    }
};
