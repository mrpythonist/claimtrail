<?php

namespace App\Console\Commands;

use App\Models\CommunicationChannel;
use App\Services\CommunicationService;
use Illuminate\Console\Command;

/**
 * Test a communication channel from the console (v1.3.3).
 *
 * Authenticates the channel and records a test delivery,
 * so the test is visible in the delivery history. No live
 * call is made: the mock records locally and Slack and
 * Teams are schema-only.
 */
class TestCommunicationChannelCommand extends Command
{
    protected $signature = 'comms:test {channel : UUID of the channel to test}';

    protected $description = 'Test a communication channel and record a test delivery';

    public function handle(CommunicationService $comms): int
    {
        $channel = CommunicationChannel::withoutGlobalScope('organization')
            ->whereKey((string) $this->argument('channel'))
            ->first();

        if ($channel === null) {
            $this->error('No communication channel with that ID.');

            return self::FAILURE;
        }

        // The console has no signed-in user: the test is
        // recorded with a null actor, the same as a
        // scheduler-triggered sync run.
        $result = $comms->testChannel($channel, null);

        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
