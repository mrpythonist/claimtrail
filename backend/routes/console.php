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
