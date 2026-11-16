<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * There is no claim_id column yet. Claims arrive in Milestone 3,
     * and that milestone's migration adds a nullable claim reference.
     */
    public function up(): void
    {
        Schema::create('evidence_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shipment_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_category');
            $table->string('original_filename');
            $table->string('storage_key');
            $table->string('disk');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('verification_status')->default('pending');
            $table->timestamps();

            $table->index(['organization_id', 'shipment_id']);
            $table->index(['organization_id', 'shipment_event_id']);
            $table->index(['organization_id', 'document_category']);
            $table->index(['organization_id', 'verification_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evidence_files');
    }
};
