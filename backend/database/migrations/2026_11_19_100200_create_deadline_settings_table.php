<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The unique index covers the non-null case; null scope values
     * are distinct in unique indexes, so a second global setting is
     * prevented in application validation, not by this constraint.
     */
    public function up(): void
    {
        Schema::create('deadline_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type');
            $table->string('scope_value')->nullable();
            $table->integer('submit_within_days');
            $table->json('reminder_days_before')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'scope_type', 'scope_value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deadline_settings');
    }
};
