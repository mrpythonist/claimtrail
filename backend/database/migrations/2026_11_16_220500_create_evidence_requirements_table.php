<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A null event_category_id means the requirement applies to every
     * event type. The unique index covers the non-null case; null
     * duplicates are prevented in application validation, because
     * unique indexes treat nulls as distinct values.
     */
    public function up(): void
    {
        Schema::create('evidence_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_category');
            $table->boolean('is_required')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'event_category_id', 'document_category'],
                'evidence_requirements_unique'
            );
            $table->index(['organization_id', 'event_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evidence_requirements');
    }
};
