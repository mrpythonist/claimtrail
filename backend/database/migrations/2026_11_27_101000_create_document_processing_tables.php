<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI document processing tables (v2.0.1).
 *
 * A document extraction is made from one evidence file. It
 * records the extracted text, the rule-based classification,
 * the extracted fields with a confidence each, the shipment
 * the reference matched (exact match only), and whether a
 * human still needs to look. Field rows keep the extracted
 * value and the human decision side by side, so the accuracy
 * monitor can compare them. Duplicates are flagged, never
 * deleted: the same content hash in one organization marks a
 * later upload as a duplicate of the earliest one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('evidence_file_id')->nullable();
            $table->string('original_filename', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // SHA-256 of the file contents as processed.
            $table->string('content_sha256', 64)->nullable();
            // The OCR provider that ran: 'local' (the mock/local
            // provider). A real cloud provider is a later swap.
            $table->string('provider', 40)->default('local');
            // bill_of_lading, proof_of_delivery, invoice,
            // rate_confirmation, detention_notice, other,
            // unclassified
            $table->string('classification', 40)->nullable();
            $table->decimal('classification_confidence', 5, 2)->nullable();
            // pending, processing, completed, needs_verification,
            // verified, rejected, failed
            $table->string('status', 20)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->uuid('matched_shipment_id')->nullable();
            $table->decimal('match_confidence', 5, 2)->nullable();
            // exact, candidate, none, manual
            $table->string('match_method', 20)->default('none');
            // Reference candidates from the text with what each
            // matched (or null): [{value, source,
            // matched_shipment_id, matched_field}].
            $table->json('match_candidates')->nullable();
            $table->boolean('match_dismissed')->default(false);
            $table->boolean('is_duplicate')->default(false);
            $table->uuid('duplicate_of_id')->nullable();
            $table->uuid('near_duplicate_of_id')->nullable();
            $table->text('near_duplicate_reason')->nullable();
            $table->text('extracted_text')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('evidence_file_id')->references('id')->on('evidence_files')->nullOnDelete();
            $table->foreign('matched_shipment_id')->references('id')->on('shipments')->nullOnDelete();
            $table->foreign('duplicate_of_id')->references('id')->on('document_extractions')->nullOnDelete();
            $table->foreign('near_duplicate_of_id')->references('id')->on('document_extractions')->nullOnDelete();
            $table->index(['organization_id', 'evidence_file_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'classification']);
            $table->index(['organization_id', 'content_sha256']);
            $table->index(['organization_id', 'matched_shipment_id']);
        });

        Schema::create('document_extraction_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('extraction_id');
            $table->string('field_name', 60);
            $table->string('field_label', 120);
            $table->text('field_value')->nullable();
            // 0-100. Labelled matches score 95, keyword-proximity
            // matches 80, bare pattern matches 60 (see
            // DocumentFieldExtractor).
            $table->decimal('confidence', 5, 2);
            $table->text('verified_value')->nullable();
            // pending, verified, corrected, rejected
            $table->string('verification_status', 20)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('extraction_id')->references('id')->on('document_extractions')->cascadeOnDelete();
            $table->unique(['extraction_id', 'field_name']);
            $table->index(['organization_id', 'extraction_id']);
            $table->index(['organization_id', 'field_name', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extraction_fields');
        Schema::dropIfExists('document_extractions');
    }
};
