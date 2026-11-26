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

// Subscriptions whose period has ended are renewed daily:
// trials convert (or lapse to Free without a payment method),
// cancellations at period end become Free, pending downgrades
// apply, paid plans are charged, and success-fee plans get
// their monthly arrears invoice.
Schedule::command('billing:renew')->dailyAt('03:30');

// Past-due invoices are retried at the configured delays, and
// organizations 14+ days past due are downgraded to Free.
Schedule::command('billing:dunning')->dailyAt('04:00');

// Production monitoring runs hourly: health checks, failed job
// count, and queue depth, stored for the operations dashboard.
// A failing run writes a warning to the log; there is no
// alerting channel yet, so the dashboard is where a failure
// is seen.
Schedule::command('monitoring:check')->hourly();

// The latest completed backup is re-hashed against its manifest
// weekly on Sunday at 04:00, so a silently rotting backup is
// caught before it is needed. A failure writes to the log.
Schedule::command('backup:verify')->weeklyOn(0, '04:00');

// Due scheduled imports run daily at 04:30: active daily and
// weekly schedules whose next run is due import their stored
// file with the saved mapping. Manual schedules never run
// here; they run on demand from the API.
Schedule::command('imports:run-scheduled')->dailyAt('04:30');
