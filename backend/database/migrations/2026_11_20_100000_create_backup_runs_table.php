<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backup runs (v1.1.3).
 *
 * One row per backup attempt. Backups are deployment-wide (the
 * whole database plus every evidence and package file), and the
 * row attributes the run to the organization that requested it;
 * scheduled runs have no organization. The path points at the
 * run's directory on the backup disk, which holds the database
 * copy, file copies, and a manifest.json with SHA-256 checksums.
 * The checksum is the SHA-256 of the database copy.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->string('driver');
            $table->string('status')->default('running');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
