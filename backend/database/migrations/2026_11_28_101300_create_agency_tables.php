<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agency management tables (v2.1.0).
     *
     * partner_links: one row per agency-to-carrier relationship.
     *   The agency organization manages the carrier organization;
     *   the link is created pending and only becomes active when
     *   an owner or admin of the CARRIER organization approves it,
     *   so an agency cannot claim a carrier unilaterally. A link
     *   records the referral code that brought the carrier in and
     *   the plan the reseller assigned, if any. A carrier
     *   organization can belong to several agencies at once.
     * referral_codes: codes an agency issues; attribution is
     *   recorded on the partner link, and the use count is derived
     *   from the links that cite the code.
     * commission_rules: one default rule per agency (a null
     *   partner_link_id) plus optional per-carrier overrides. The
     *   rate applies to recorded payments only; a cap, when set,
     *   bounds the earned commission for the period the rule
     *   covers. Earned commission is computed on read from the
     *   payment ledger, never stored as money owed or paid.
     * agency_carrier_grants: per-carrier permission grants for
     *   agency members. Owners and admins of the agency org have
     *   access to every linked carrier without a grant; other
     *   members need a grant for each carrier they may touch, with
     *   a view or manage scope.
     */
    public function up(): void
    {
        Schema::create('partner_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('carrier_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('referred_by_code', 32)->nullable();
            $table->string('assigned_plan', 32)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['agency_organization_id', 'carrier_organization_id']);
            $table->index(['agency_organization_id', 'status']);
            $table->index(['carrier_organization_id', 'status']);
        });

        Schema::create('referral_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'active']);
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('partner_link_id')->nullable()->constrained('partner_links')->cascadeOnDelete();
            $table->decimal('rate_percent', 5, 2);
            $table->unsignedInteger('cap_cents')->nullable();
            $table->string('basis', 16)->default('paid');
            $table->boolean('active')->default(true);
            $table->date('effective_from')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['agency_organization_id', 'partner_link_id']);
        });

        Schema::create('agency_carrier_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_link_id')->constrained('partner_links')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 16)->default('view');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['partner_link_id', 'user_id']);
            $table->index(['partner_link_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_carrier_grants');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('partner_links');
    }
};
