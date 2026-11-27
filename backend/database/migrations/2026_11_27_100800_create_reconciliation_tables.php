<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Financial reconciliation (v1.5.0).
     *
     * claim_payments is the append-only payment ledger: one row per
     * payment an organization records against a claim, in the
     * claim's currency. Partial payments are normal; the claim's
     * recovered_amount stays the authoritative paid total and is
     * kept in sync by ReconciliationService. reconciliation_reports
     * stores generated reports (period, totals, discrepancies) so
     * a report can be listed and re-read after it is generated.
     * Neither table moves real money: billing runs on a mock
     * provider, and the ledger only records what the org says it
     * received.
     */
    public function up(): void
    {
        Schema::create('claim_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('payment_method', 32);
            $table->string('payment_reference', 255)->nullable();
            $table->date('paid_at');
            $table->string('notes', 1000)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'paid_at']);
            $table->index(['claim_id', 'paid_at']);
        });

        Schema::create('reconciliation_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->json('totals');
            $table->json('discrepancies');
            $table->foreignUuid('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['organization_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_reports');
        Schema::dropIfExists('claim_payments');
    }
};
