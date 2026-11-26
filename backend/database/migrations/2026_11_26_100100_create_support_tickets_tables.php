<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer support tickets (v1.2.3).
 *
 * A ticket is a support conversation inside one organization: the
 * requester is a member, the triage and replies are done by the
 * organization's owner and admins, and the operator works tickets
 * across organizations with `php artisan support:list` /
 * `support:show`. There is no email in or out yet: the mailer is
 * the log driver by default, so replies are delivered as in-app
 * notifications instead (see SupportTicketService).
 *
 * Messages are either public (every member of the organization can
 * read them) or internal notes (owner and admin only). Ticket
 * bodies and messages are never written to the audit log: they may
 * contain customer data the audit trail should not duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->uuid('requester_id')->nullable();
            $table->foreign('requester_id')->references('id')->on('users')->nullOnDelete();
            $table->string('subject', 255);
            $table->string('category', 50)->default('other');
            $table->string('priority', 50)->default('normal');
            $table->string('status', 50)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'category']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('support_ticket_id');
            $table->foreign('support_ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
