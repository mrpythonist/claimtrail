<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Reviewer notes are a separate append-only record from status
     * history: a note is a comment on the claim, not a status change.
     * The review action (approve / request changes) writes both a
     * note row and a status history row through the transition
     * service, so the decision and its reasoning stay linked.
     */
    public function up(): void
    {
        Schema::create('claim_review_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note');
            $table->timestamps();

            $table->index(['organization_id', 'claim_id']);
            $table->index(['claim_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claim_review_notes');
    }
};
