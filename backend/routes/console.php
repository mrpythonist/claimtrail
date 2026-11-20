<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Claim deadline statuses are recomputed hourly. Due-soon and
// overdue deadlines notify the organization's members (one
// notification per deadline per day).
Schedule::command('claimtrail:refresh-deadline-statuses')->hourly();

// Orphaned evidence objects (a file landed but its database row
// did not) are swept weekly. The command only touches objects
// older than one hour, so in-flight uploads are safe.
Schedule::command('claimtrail:cleanup-orphan-evidence')->weekly();

// A full backup (database + stored files) is taken daily at
// 02:00. The run is unattributed (no organization); an operator
// can also trigger one from the security page.
Schedule::command('backup:create')->dailyAt('02:00');

// Records older than the configured retention are purged
// weekly on Sunday at 03:00. The command is tenant-scoped per
// organization and writes one audit row per organization.
Schedule::command('retention:purge')->weeklyOn(0, '03:00');
