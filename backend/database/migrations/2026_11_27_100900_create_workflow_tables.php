<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow engine tables (v2.0.0).
 *
 * A workflow belongs to one organization and fires on one trigger.
 * Its conditions are evaluated against the claim, shipment,
 * readiness, and deadline as recorded; its steps are the ordered
 * actions the engine runs. Every run is recorded with one row per
 * step, and approval checkpoints get their own decision rows, so
 * everything a workflow did is auditable afterwards. Workflows
 * never send anything anywhere: actions are internal only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            // claim.created, claim.submitted, status.changed,
            // deadline.approaching, deadline.overdue,
            // evidence.completed, evidence.incomplete,
            // payment.recorded
            $table->string('trigger', 40);
            $table->boolean('is_active')->default(true);
            // [{field, operator, value}] — all must match (AND).
            $table->json('conditions')->nullable();
            // Ordered steps: [{type, ...params}].
            $table->json('steps');
            // Days before a deadline at which the
            // deadline.approaching trigger fires. Null means 7.
            $table->unsignedSmallInteger('deadline_days_before')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'trigger', 'is_active']);
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('workflow_id');
            $table->uuid('claim_id');
            $table->string('trigger', 40);
            // running, waiting_approval, completed, failed, rejected
            $table->string('status', 20)->default('running');
            // The user whose action triggered the run, or the
            // workflow creator when the run was system-triggered.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('current_step_index')->default(0);
            $table->text('failure_reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('workflow_id')->references('id')->on('workflows')->cascadeOnDelete();
            $table->foreign('claim_id')->references('id')->on('claims')->cascadeOnDelete();
            $table->index(['organization_id', 'workflow_id']);
            $table->index(['organization_id', 'claim_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('workflow_run_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('workflow_run_id');
            $table->unsignedSmallInteger('step_index');
            $table->string('step_type', 40);
            // pending, running, completed, failed, skipped,
            // waiting_approval
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->unique(['workflow_run_id', 'step_index']);
            $table->index(['organization_id', 'workflow_run_id']);
        });

        Schema::create('workflow_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('workflow_run_id');
            $table->unsignedSmallInteger('step_index');
            // The role that may decide this checkpoint.
            $table->string('role', 40);
            // pending, approved, rejected
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->index(['organization_id', 'workflow_run_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_approvals');
        Schema::dropIfExists('workflow_run_steps');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflows');
    }
};
