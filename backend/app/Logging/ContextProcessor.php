<?php

namespace App\Logging;

use App\Support\Correlation;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds request context to every log record.
 *
 * The correlation id ties a log line to the request (or job run)
 * that produced it and to the audit rows from the same request.
 * The app name and environment let a log aggregator tell
 * deployments apart. The processor reads the Correlation
 * singleton, which the CorrelationId middleware sets for HTTP
 * requests; console commands and jobs set one per run, and a
 * missing id is recorded as null rather than crashing.
 */
class ContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $correlationId = null;

        try {
            $correlationId = app(Correlation::class)->get();
        } catch (\Throwable) {
            // The container may not be booted in early logging.
        }

        $record->extra['correlation_id'] = $correlationId;
        $record->extra['app'] = config('app.name');
        $record->extra['environment'] = config('app.env');

        return $record;
    }
}
