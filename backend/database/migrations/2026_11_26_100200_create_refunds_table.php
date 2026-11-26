<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice refunds (v1.2.3).
 *
 * A refund returns money already paid on an invoice: full or
 * partial, with a required reason, recorded against the mock
 * provider (or real Stripe later) and in the audit trail. The
 * refund policy itself is not decided here: until Aqib publishes
 * a written policy, refunds are issued case by case by the
 * organization's billing managers, and the reason is the record
 * of why. See docs/BILLING.md, "Refunds and cancellations".
 *
 * `amount_refunded_cents` on the invoice is the durable total;
 * the refunds table is the line-by-line record. A refunded
 * invoice keeps its paid amount: the refund is a separate
 * movement, not an edit of the original charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('amount_refunded_cents')->default(0)->after('amount_paid_cents');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->uuid('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->uuid('requested_by')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('reason', 500);
            $table->string('status', 50)->default('pending');
            $table->string('provider_refund_id')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('amount_refunded_cents');
        });
    }
};
