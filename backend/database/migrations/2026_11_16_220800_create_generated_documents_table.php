<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Generated claim package PDFs live on the private disk, like
     * evidence files, and are served only through the authorized
     * download route. Regenerating a package creates a new row; the
     * previous file stays until the old row is superseded by the new
     * ready one, so a download never 404s mid-regeneration.
     */
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $table->string('document_type')->default('claim_package');
            $table->string('storage_key')->nullable();
            $table->string('disk')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('includes_internal_notes')->default(false);
            $table->string('status')->default('queued');
            $table->text('error_message')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'claim_id']);
            $table->index(['claim_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
