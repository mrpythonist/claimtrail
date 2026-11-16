<?php

namespace App\Services;

use App\Models\ClaimSequence;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Generates per-organization claim reference numbers like CLM-2026-0001.
 *
 * The counter row is locked inside a transaction before incrementing,
 * so two claims created at the same moment in one organization never
 * receive the same reference. The sequence restarts each calendar
 * year. Callers must create the claim in the same transaction (or
 * accept a gap in the sequence if the claim insert later fails).
 */
class ClaimReferenceService
{
    /**
     * Reserve and return the next reference for an organization.
     */
    public function nextReference(Organization $organization): string
    {
        $year = (int) now()->format('Y');

        return DB::transaction(function () use ($organization, $year) {
            $sequence = ClaimSequence::where('organization_id', $organization->getKey())
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = ClaimSequence::create([
                    'organization_id' => $organization->getKey(),
                    'year' => $year,
                    'last_number' => 0,
                ]);
            }

            $sequence->increment('last_number');

            return sprintf('CLM-%d-%04d', $year, $sequence->last_number);
        });
    }
}
