<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security events (v1.1.3).
 *
 * A small, append-only log of security-relevant events that do
 * not belong in the tenant audit trail: failed logins (no
 * organization is known yet) and authorization denials. Rows are
 * attributed to an organization when one was resolved for the
 * request, and are readable through the security endpoints by
 * owners and admins of that organization. Retention purging
 * applies (see config/retention.php).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('ip_address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
