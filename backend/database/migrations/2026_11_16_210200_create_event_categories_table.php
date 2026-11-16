<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A null organization_id marks a system category, seeded by
     * EventCategorySeeder and visible to every organization. The
     * unique index covers organization and slug together; SQLite and
     * PostgreSQL both treat nulls as distinct in unique indexes, so
     * system slugs are protected by the seeder using firstOrCreate
     * rather than by this constraint.
     */
    public function up(): void
    {
        Schema::create('event_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_categories');
    }
};
