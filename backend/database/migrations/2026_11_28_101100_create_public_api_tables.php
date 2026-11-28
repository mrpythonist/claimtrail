<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public API and integrations tables (v2.0.2).
 *
 * api_keys: Bearer keys for the public API. Only the SHA-256 hash
 * is stored; the full key is shown exactly once at creation and
 * never again. A key belongs to one organization and carries a
 * list of scopes. status is active, rotating (an old key inside
 * its rotation grace window), or revoked. kind is standard or
 * broker: broker keys are read/status only.
 *
 * webhook_endpoints: organization webhook subscriptions. The
 * target URL and the HMAC signing secret are stored encrypted;
 * the secret is shown once at creation. events is the list of
 * event types the endpoint receives.
 *
 * webhook_deliveries: one row per delivery attempt batch, with
 * the event payload, attempt count, last HTTP status, and the
 * final outcome. Retries are bounded; a delivery that exhausts
 * them stays failed, never silently dropped.
 *
 * accounting_connections: mock accounting provider connections
 * (QuickBooks / Xero modelled schema-only). No live credentials
 * exist and no real sync runs; the mock provider returns
 * deterministic dummy results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 100);
            $table->string('kind', 20)->default('standard');
            $table->string('key_prefix', 20);
            $table->string('key_hash', 64)->unique();
            $table->json('scopes');
            $table->string('status', 20)->default('active');
            $table->string('broker_name', 255)->nullable();
            $table->uuid('rotated_from_id')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 100);
            $table->text('url');
            $table->text('secret');
            $table->json('events');
            $table->string('status', 20)->default('active');
            $table->timestamp('last_delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('webhook_endpoint_id');
            $table->uuid('organization_id');
            $table->string('event_type', 100);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('http_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index(['organization_id', 'event_type']);
            $table->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('accounting_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('provider', 50);
            $table->string('name', 100);
            $table->string('status', 20)->default('connected');
            $table->json('settings')->nullable();
            $table->json('last_sync_summary')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'provider']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_connections');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_keys');
    }
};
