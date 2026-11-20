<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup disk and path
    |--------------------------------------------------------------------------
    |
    | Backups live on a private disk, never a public one, under the
    | configured path. Each backup gets its own directory named after
    | the backup run id, holding the database copy, a copy of every
    | evidence and package file, and a manifest.json with SHA-256
    | checksums for all of it. The manifest is the source of truth
    | for restore verification.
    |
    */

    'disk' => env('BACKUP_DISK', 'private'),

    'path' => env('BACKUP_PATH', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL tools
    |--------------------------------------------------------------------------
    |
    | The pg_dump / pg_restore binaries used when the configured
    | database driver is pgsql. If the binary is not on PATH the
    | backup fails with a clear message instead of pretending to
    | succeed. There is no live-PostgreSQL backup path tested on
    | this build VM; see docs/OPERATIONS.md.
    |
    */

    'pg_dump_path' => env('PG_DUMP_PATH', 'pg_dump'),

    'pg_restore_path' => env('PG_RESTORE_PATH', 'pg_restore'),

    /*
    |--------------------------------------------------------------------------
    | Retention of backup runs
    |--------------------------------------------------------------------------
    |
    | How long backup run records (and their directories) are kept
    | before an operator may prune them. Not yet enforced by a
    | command; listed here so the value is a conscious choice.
    |
    */

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

];
