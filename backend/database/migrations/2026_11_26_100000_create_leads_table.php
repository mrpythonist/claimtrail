<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads captured by the public marketing site (v1.2.2).
 *
 * A lead is a person who asked for a demo (or, later, another
 * marketing form) before they have an account. Leads are not
 * tenant-scoped: they exist before any organization does. They are
 * reviewed by the operator with `php artisan leads:list`; there is
 * no admin UI for them yet.
 *
 * No IP address or user agent is stored: the form asks for what a
 * person would say in an email, nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('company', 255)->nullable();
            $table->string('fleet_size', 50)->nullable();
            $table->text('message')->nullable();
            $table->string('source', 50)->default('demo_request');
            $table->string('status', 50)->default('new');
            $table->timestamp('contacted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
