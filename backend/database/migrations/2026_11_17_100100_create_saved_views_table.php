<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Saved list views are personal: each member keeps their own
     * named filter sets per resource type within an organization.
     * The filter payload is stored as JSON and applied client-side
     * as query parameters; the backend never executes it.
     */
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 20);
            $table->string('name', 100);
            $table->json('filters');
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'resource_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
