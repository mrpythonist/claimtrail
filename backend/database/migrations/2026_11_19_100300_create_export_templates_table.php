<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('export_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type');
            $table->string('name');
            $table->json('columns');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'resource_type', 'name']);
            $table->index(['organization_id', 'resource_type', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_templates');
    }
};
