<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deadlines are supplied by the customer, never inferred from
     * event types or broker rules. Only one deadline row per claim is
     * active at a time: superseded rows are kept with superseded_at
     * set, so the history of deadline changes is preserved.
     */
    public function up(): void
    {
        Schema::create('claim_deadlines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $table->dateTime('deadline_at');
            $table->string('timezone');
            $table->text('source_reference')->nullable();
            $table->json('reminder_days_before')->nullable();
            $table->string('status')->default('upcoming');
            $table->dateTime('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'deadline_at']);
            $table->index(['claim_id', 'superseded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claim_deadlines');
    }
};
