<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for queued jobs (v1.1.1).
 *
 * A job claims its key before doing work and marks it completed
 * afterwards. A redelivered job that finds a completed key skips
 * its work instead of running twice; a failed run releases its
 * key so a retry can claim it again. A "running" row older than
 * the stale threshold (see JobExecution) is treated as abandoned
 * and may be re-claimed, so a killed worker cannot block a job
 * forever.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('job');
            $table->string('status')->default('running');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['job', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_executions');
    }
};
