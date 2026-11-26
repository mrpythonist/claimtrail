<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email intake tables (v1.3.0).
 *
 * inbound_email_addresses: one row per organization-specific
 * inbound address (local_part is globally unique, so two
 * organizations can never share an address).
 *
 * inbound_emails: one row per received email. message_id is
 * unique per organization, so a provider redelivery is stored
 * once and processed once.
 *
 * inbound_email_attachments: one row per decoded attachment,
 * stored on the private disk. evidence_file_id links to the
 * EvidenceFile row created when the email is matched to a
 * shipment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_email_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('local_part')->unique();
            $table->string('label')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'active']);
        });

        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('inbound_email_address_id')->nullable();
            $table->string('from');
            $table->string('to');
            $table->string('subject')->nullable();
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->string('message_id');
            $table->string('status')->default('received');
            $table->uuid('matched_shipment_id')->nullable();
            $table->json('extracted_references')->nullable();
            $table->string('match_reason')->nullable();
            $table->string('match_confidence')->nullable();
            $table->string('dismissed_reason')->nullable();
            $table->text('processing_notes')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('inbound_email_address_id')->references('id')->on('inbound_email_addresses')->nullOnDelete();
            $table->foreign('matched_shipment_id')->references('id')->on('shipments')->nullOnDelete();
            $table->unique(['organization_id', 'message_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'matched_shipment_id']);
        });

        Schema::create('inbound_email_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('inbound_email_id');
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_path');
            $table->string('checksum', 64)->nullable();
            $table->uuid('evidence_file_id')->nullable();
            $table->timestamps();

            $table->foreign('inbound_email_id')->references('id')->on('inbound_emails')->cascadeOnDelete();
            $table->foreign('evidence_file_id')->references('id')->on('evidence_files')->nullOnDelete();
            $table->index('inbound_email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_email_attachments');
        Schema::dropIfExists('inbound_emails');
        Schema::dropIfExists('inbound_email_addresses');
    }
};
