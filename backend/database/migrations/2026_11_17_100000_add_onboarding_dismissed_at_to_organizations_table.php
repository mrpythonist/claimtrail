<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The onboarding panel is dismissed organization-wide by someone
     * who manages the organization. The step completion itself is
     * derived live from the organization's data, so no per-step
     * columns are needed.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('settings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('onboarding_dismissed_at');
        });
    }
};
