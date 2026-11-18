<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Attaches the context processor to a log channel.
 *
 * Used as a `tap` in the logging config: Laravel calls this with
 * the channel's logger after building it. Both the context
 * processor and the PSR message processor are pushed, so
 * `{placeholders}` in messages still interpolate.
 */
class AddContextProcessor
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new ContextProcessor);
        $logger->pushProcessor(new PsrLogMessageProcessor);
    }
}
