<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enterprise readiness tables (v2.0.3).
     *
     * security_policies: one row per organization with the
     *   security policy an owner configured (defaults come from
     *   config/enterprise.php until a row exists).
     * alert_rules / alerts: monitoring rules an organization
     *   defined plus the breaches they produced. An alert keeps
     *   its rule id nullable: deleting a rule keeps the history.
     * slos: one row per organization per SLO key, seeded from
     *   the config defaults on first read.
     * load_test_runs: bounded local synthetic load runs.
     * security_assessments: generated self-assessment reports.
     * disaster_recovery_drills: backup verification and
     *   restore dry-run drills, with their measured result.
     */
    public function up(): void
    {
        Schema::create('security_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('password_min_length')->default(8);
            $table->boolean('password_require_numbers')->default(false);
            $table->boolean('password_require_symbols')->default(false);
            $table->unsignedInteger('session_timeout_minutes')->default(120);
            $table->unsignedInteger('max_concurrent_sessions')->nullable();
            $table->json('allowed_ip_ranges')->nullable();
            $table->boolean('require_two_factor')->default(false);
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('metric', 32);
            $table->string('operator', 8)->default('gt');
            $table->decimal('threshold', 12, 4);
            $table->unsignedInteger('window_minutes')->default(60);
            $table->boolean('enabled')->default(true);
            $table->string('severity', 16)->default('warning');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'metric']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('alert_rule_id')->nullable()->nullOnDelete();
            $table->string('metric', 32);
            $table->decimal('observed_value', 12, 4);
            $table->decimal('threshold', 12, 4);
            $table->string('severity', 16)->default('warning');
            $table->string('status', 16)->default('active');
            $table->text('message');
            $table->timestamp('triggered_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'triggered_at']);
        });

        Schema::create('slos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32);
            $table->string('name', 120);
            $table->string('kind', 32);
            $table->decimal('target_value', 12, 4);
            $table->string('target_unit', 8);
            $table->unsignedInteger('window_days')->default(30);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });

        Schema::create('load_test_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('target', 32);
            $table->unsignedInteger('requests');
            $table->string('status', 16)->default('completed');
            $table->unsignedInteger('total_requests')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->decimal('avg_ms', 10, 2)->default(0);
            $table->decimal('p95_ms', 10, 2)->default(0);
            $table->decimal('max_ms', 10, 2)->default(0);
            $table->decimal('requests_per_second', 10, 2)->default(0);
            $table->json('detail')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('security_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('overall', 16);
            $table->unsignedInteger('open_findings')->default(0);
            $table->unsignedInteger('total_checks')->default(0);
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('warnings')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->json('findings');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['organization_id', 'generated_at']);
        });

        Schema::create('disaster_recovery_drills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16)->default('completed');
            $table->json('result')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['organization_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disaster_recovery_drills');
        Schema::dropIfExists('security_assessments');
        Schema::dropIfExists('load_test_runs');
        Schema::dropIfExists('slos');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('security_policies');
    }
};
