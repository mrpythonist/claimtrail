<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication integration tables (v1.3.3).
 *
 * communication_channels: one row per Slack, Teams, or mock
 * channel for an organization. provider is the driver name
 * ('mock', 'slack', or 'teams'); webhook_url holds the
 * incoming-webhook URL, stored encrypted and never returned
 * by the API (the mock needs none). settings selects which
 * events the channel receives (claim_created,
 * evidence_received, driver_link_used, sync_completed,
 * delivery_failed — all true by default). status is active
 * or disabled; a disabled channel never receives a message.
 * last_error carries the latest failure message for the
 * monitoring view.
 *
 * communication_deliveries: one row per message attempt,
 * whatever started it (an API test, a service send, or the
 * comms:test command). status is logged (recorded locally,
 * no live delivery — every provider in v1.3.3), sent (a
 * live delivery, never in v1.3.3), or failed. payload holds
 * the would-be vendor payload, so the history shows exactly
 * what would have been sent.
 *
 * driver_evidence_links: one row per signed submission link
 * for a driver. token_hash stores the sha256 of the token;
 * only the hash is stored, the raw token is shown once at
 * creation and sent in the email. A link is scoped to one
 * shipment, or one claim, or one shipment plus its claim,
 * and expires at expires_at. max_uses caps submissions
 * (1 by default: single-use); used_count tracks them.
 * revoked_at revokes a link before expiry; expiry_notified_at
 * marks that the expiry notification was sent.
 *
 * driver_evidence_submissions: one row per evidence file a
 * driver submitted through a link, tying the link to the
 * stored evidence file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('provider');
            $table->string('name');
            $table->string('status')->default('active');
            $table->text('webhook_url')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'provider']);
        });

        Schema::create('communication_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('communication_channel_id');
            $table->string('event');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status')->default('logged');
            $table->text('detail')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('communication_channel_id')->references('id')->on('communication_channels')->cascadeOnDelete();
            $table->index(['organization_id', 'communication_channel_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('driver_evidence_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('shipment_id')->nullable();
            $table->uuid('claim_id')->nullable();
            $table->string('token_hash')->unique();
            $table->string('driver_name')->nullable();
            $table->string('driver_email')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expiry_notified_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->foreign('claim_id')->references('id')->on('claims')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'shipment_id']);
            $table->index(['organization_id', 'claim_id']);
        });

        Schema::create('driver_evidence_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('driver_evidence_link_id');
            $table->uuid('shipment_id')->nullable();
            $table->uuid('claim_id')->nullable();
            $table->uuid('evidence_file_id')->nullable();
            $table->string('driver_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('driver_evidence_link_id')->references('id')->on('driver_evidence_links')->cascadeOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipments')->nullOnDelete();
            $table->foreign('claim_id')->references('id')->on('claims')->nullOnDelete();
            $table->foreign('evidence_file_id')->references('id')->on('evidence_files')->nullOnDelete();
            $table->index(['organization_id', 'driver_evidence_link_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_evidence_submissions');
        Schema::dropIfExists('driver_evidence_links');
        Schema::dropIfExists('communication_deliveries');
        Schema::dropIfExists('communication_channels');
    }
};
