<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Money columns keep the three financial facts separate: what was
     * requested, what the broker accepted, and what was actually paid.
     * accepted_amount and recovered_amount are never defaulted from
     * requested_amount; each is recorded only when its event happens.
     */
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('claim_reference');
            $table->foreignUuid('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shipment_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claim_category');
            $table->text('description')->nullable();
            $table->decimal('requested_amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('accepted_amount', 12, 2)->nullable();
            $table->decimal('recovered_amount', 12, 2)->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('external_broker_reference')->nullable();
            $table->dateTime('submission_deadline_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('status')->default('potential');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'claim_reference']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'shipment_id']);
            $table->index(['organization_id', 'claim_category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
