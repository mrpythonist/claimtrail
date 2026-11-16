<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Snapshots of a claim's readiness at a point in time: one row per
     * required document category. Snapshots are rewritten whenever
     * readiness is checked during review or a claim is marked ready to
     * submit. An override records who accepted the gap and why.
     */
    public function up(): void
    {
        Schema::create('claim_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $table->string('document_category');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_fulfilled')->default(false);
            $table->foreignUuid('evidence_file_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('snapshot_at');
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->dateTime('overridden_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'claim_id']);
            $table->unique(['claim_id', 'document_category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claim_requirements');
    }
};
