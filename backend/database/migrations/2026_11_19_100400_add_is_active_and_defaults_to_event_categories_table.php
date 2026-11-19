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
        Schema::table('event_categories', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('description');
            $table->decimal('default_estimated_amount', 12, 2)->nullable()->after('is_active');
            $table->string('default_currency', 3)->nullable()->after('default_estimated_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'default_estimated_amount', 'default_currency']);
        });
    }
};
