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
