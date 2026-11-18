<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHA-256 checksum for evidence files (v1.1.1, upload reliability).
 *
 * The checksum is computed from the uploaded file at store time.
 * Downloads on local disks verify the stored object against it, so
 * a truncated or corrupted object is reported instead of served.
 * Existing rows keep a null checksum: they were uploaded before
 * checksums existed and are served without verification.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('evidence_files', function (Blueprint $table) {
            $table->string('checksum', 64)->nullable()->after('file_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('evidence_files', function (Blueprint $table) {
            $table->dropColumn('checksum');
        });
    }
};
