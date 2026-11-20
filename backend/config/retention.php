<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data retention defaults
    |--------------------------------------------------------------------------
    |
    | How many days each kind of record is kept, per organization,
    | before the weekly retention purge may delete it. A value of 0
    | disables purging for that kind: the records are kept forever.
    | Organizations may override these defaults through the
    | retention settings endpoint (PUT /retention); the overrides
    | live in the organization's settings JSON, never in this file.
    |
    | Generated documents are only ever purged when they failed:
    | a ready claim package is the record of a submitted claim and
    | is never deleted by retention.
    |
    | Job executions and security events without an organization
    | are global rows; they are purged by the all-organizations
    | run only, using the same defaults.
    |
    */

    'audit_logs_days' => (int) env('RETENTION_AUDIT_LOG_DAYS', 365),

    'notifications_days' => (int) env('RETENTION_NOTIFICATION_DAYS', 90),

    'job_executions_days' => (int) env('RETENTION_JOB_EXECUTION_DAYS', 30),

    'failed_documents_days' => (int) env('RETENTION_FAILED_DOCUMENT_DAYS', 30),

    'security_events_days' => (int) env('RETENTION_SECURITY_EVENT_DAYS', 180),

];
