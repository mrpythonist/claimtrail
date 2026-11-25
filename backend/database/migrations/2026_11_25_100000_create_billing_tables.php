<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription billing tables (v1.2.0).
 *
 * The provider is the mock (config/billing.php); the app's own
 * records are the source of truth and the provider only supplies
 * provider-side ids. Money is integer cents, USD.
 *
 * - subscriptions: one row per organization (an org always has
 *   exactly one, created lazily as Free). Status trialing/active/
 *   past_due/canceled. A downgrade is scheduled, not immediate:
 *   pending_plan/pending_interval/pending_extra_carriers apply at
 *   the next renewal.
 * - invoices + invoice_lines: the invoice history. Numbers are
 *   INV-YYYY-NNNN per organization, from invoice_sequences.
 * - billing_usage_records: one row per payment recorded while the
 *   org is on the success-fee plan. fee_cents is 15% of the
 *   recovered amount; the $49/mo first-year cap is applied at
 *   invoice time, as a cap-adjustment line, so the fee stays
 *   auditable line by line.
 * - billing_events: every webhook event received, deduplicated by
 *   provider_event_id, so a redelivered event never double-applies.
 * - checkout_sessions: open mock checkout sessions.
 * - portal_sessions: mock billing portal sessions (token + expiry).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->string('plan')->default('free');
            $table->string('status')->default('active');
            $table->string('interval')->default('monthly');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('plan_started_at')->nullable();
            $table->timestamp('success_fee_cap_until')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->string('pending_plan')->nullable();
            $table->string('pending_interval')->nullable();
            $table->unsignedInteger('pending_extra_carriers')->nullable();
            $table->string('provider')->default('mock');
            $table->string('provider_customer_id')->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->string('payment_method_token')->nullable();
            $table->string('payment_method_brand')->nullable();
            $table->string('payment_method_last4')->nullable();
            $table->unsignedInteger('extra_carriers')->default(0);
            $table->unsignedInteger('credit_balance_cents')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unsignedInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->uuid('subscription_id')->nullable();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            // Invoice numbers are per-organization, per-year
            // (INV-2026-0001 for each organization's first invoice),
            // so the number is only unique within the organization.
            // A global unique would stop the second organization
            // from ever being invoiced.
            $table->string('number');
            $table->unique(['organization_id', 'number']);
            $table->string('status')->default('open');
            $table->string('currency')->default('USD');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('amount_paid_cents')->default(0);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedInteger('payment_attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('provider_invoice_id')->nullable();
            $table->string('provider_charge_id')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'next_retry_at']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->string('kind');
            $table->string('description');
            $table->integer('amount_cents');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'created_at']);
        });

        Schema::create('billing_usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->uuid('subscription_id')->nullable();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->uuid('claim_id')->unique();
            $table->foreign('claim_id')->references('id')->on('claims')->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('fee_cents');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('recorded_at');
            $table->timestamp('invoiced_at')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'period_start']);
            $table->index(['invoiced_at']);
        });

        Schema::create('billing_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->string('type');
            $table->string('provider_event_id')->unique();
            $table->json('payload')->nullable();
            $table->string('status')->default('processed');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->string('provider_session_id')->unique();
            $table->string('plan');
            $table->string('interval')->default('monthly');
            $table->unsignedInteger('extra_carriers')->default(0);
            $table->string('status')->default('open');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('portal_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_sessions');
        Schema::dropIfExists('checkout_sessions');
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('billing_usage_records');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('subscriptions');
    }
};
