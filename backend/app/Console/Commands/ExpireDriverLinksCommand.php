<?php

namespace App\Console\Commands;

use App\Services\CommunicationService;
use Illuminate\Console\Command;

/**
 * Expire due driver evidence links (v1.3.3).
 *
 * Finds links past their expiry that have not had an
 * expiry notice yet, marks them notified, and notifies
 * the members who can manage communications. Revoked
 * links are skipped (revocation is its own state), and
 * links expired more than thirty days ago are skipped
 * too — the notice is for a fresh expiry, not archaeology.
 * Safe to run daily from the scheduler.
 */
class ExpireDriverLinksCommand extends Command
{
    protected $signature = 'comms:expire-links';

    protected $description = 'Notify members about driver evidence links that have expired';

    public function handle(CommunicationService $comms): int
    {
        $notified = $comms->expireDueLinks();

        $this->info($notified === 0
            ? 'No driver evidence links expired.'
            : "Notified members about {$notified} expired driver evidence link(s).");

        return self::SUCCESS;
    }
}
