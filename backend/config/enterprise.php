<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enterprise readiness defaults (v2.0.3)
    |--------------------------------------------------------------------------
    |
    | Configured objectives and defaults for the enterprise surface.
    | Everything here is a default an organization may override
    | through the API; nothing here is a measured production value.
    |
    | - Security policy defaults: what a fresh organization gets
    |   before an owner changes it. Password rules are enforced
    |   on password change; allowed IP ranges are check-only (the
    |   check endpoint reports whether an address is inside them,
    |   nothing at the edge enforces them); two-factor is recorded
    |   policy only, because no second factor exists yet.
    | - RPO / RTO: objectives for disaster recovery, not measured
    |   values. The daily backup schedule is what makes the 24 hour
    |   RPO plausible; the 4 hour RTO is a target for the restore
    |   drill, not a timed production restore.
    | - SLOs: the three objectives every organization starts with.
    |   Measured values come only from this instance's own recorded
    |   data (monitoring check history and the local error log).
    | - Load tests: bounded local synthetic load only. The cap keeps
    |   a run from ever looking like a production load test.
    |
    */

    'security_policy_defaults' => [
        'password_min_length' => (int) env('ENTERPRISE_PASSWORD_MIN_LENGTH', 8),
        'password_require_numbers' => false,
        'password_require_symbols' => false,
        'session_timeout_minutes' => (int) env('ENTERPRISE_SESSION_TIMEOUT_MINUTES', 120),
        'max_concurrent_sessions' => null,
        'allowed_ip_ranges' => [],
        'require_two_factor' => false,
    ],

    'rpo_minutes' => (int) env('ENTERPRISE_RPO_MINUTES', 1440),

    'rto_minutes' => (int) env('ENTERPRISE_RTO_MINUTES', 240),

    'slos' => [
        'availability' => [
            'name' => 'Availability',
            'kind' => 'availability',
            'target_value' => 99.5,
            'target_unit' => '%',
            'window_days' => 30,
        ],
        'error_rate' => [
            'name' => 'Error rate',
            'kind' => 'error_rate',
            'target_value' => 1.0,
            'target_unit' => '%',
            'window_days' => 30,
        ],
        'latency_p95' => [
            'name' => 'Latency (p95)',
            'kind' => 'latency',
            'target_value' => 500,
            'target_unit' => 'ms',
            'window_days' => 7,
        ],
    ],

    'load_test' => [
        'max_requests' => 200,
        'default_requests' => 50,
    ],

    /*
    | Alert metrics: what an alert rule can watch. Every metric is
    | measured from local data only: the database queue and failed
    | job tables, the local error log, the evidence disk, and the
    | backup run history. There is no external monitoring source.
    */
    'alert_metrics' => [
        'queue_depth' => ['label' => 'Queue depth', 'unit' => 'jobs'],
        'failed_jobs' => ['label' => 'Failed jobs', 'unit' => 'jobs'],
        'error_rate' => ['label' => 'Error rate', 'unit' => '%'],
        'disk_usage_percent' => ['label' => 'Disk usage', 'unit' => '%'],
        'backup_age_hours' => ['label' => 'Backup age', 'unit' => 'hours'],
    ],

];
