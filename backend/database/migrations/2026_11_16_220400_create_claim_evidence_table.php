<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Many-to-many link between claims and evidence files: one file
     * can support several claims, and one claim can attach several
     * files. This pivot is why evidence_files has no claim_id column:
     * a single foreign key could not represent the shared case.
     */
    public function up(): void
    {
        Schema::create('claim_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('evidence_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['claim_id', 'evidence_file_id']);
            $table->index(['evidence_file_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claim_evidence');
    }
};
