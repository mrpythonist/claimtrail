<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Claim deadline statuses are recomputed hourly. Notification
// delivery for upcoming and overdue deadlines is a Milestone 4
// concern; until then the command logs the counts.
Schedule::command('claimtrail:refresh-deadline-statuses')->hourly();
