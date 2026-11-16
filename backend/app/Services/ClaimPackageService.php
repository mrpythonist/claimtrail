<?php

namespace App\Services;

use App\Models\Claim;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Builds the claim package PDF.
 *
 * The package is a supporting evidence summary, not a guarantee of
 * acceptance: the view carries that disclaimer in its footer. Evidence
 * originals are indexed, not embedded; the package states that the
 * originals are available in ClaimTrail. Internal notes are included
 * only when the caller explicitly asks for them.
 */
class ClaimPackageService
{
    /**
     * Render the package HTML for a claim.
     *
     * Separated from PDF rendering so tests can assert on the
     * document contents directly: dompdf compresses its output
     * streams, so claim text is not searchable in the PDF bytes.
     */
    public function renderHtml(Claim $claim, bool $includeNotes = false): string
    {
        $claim->loadMissing([
            'organization',
            'shipment.carrier',
            'event.category',
            'evidenceFiles',
        ]);

        $timeline = $claim->statusHistories()->orderBy('created_at')->get();

        return view('claims.package', [
            'claim' => $claim,
            'organization' => $claim->organization,
            'shipment' => $claim->shipment,
            'event' => $claim->event,
            'evidenceFiles' => $claim->evidenceFiles,
            'timeline' => $timeline,
            'includeNotes' => $includeNotes,
            'generatedAt' => now(),
        ])->render();
    }

    /**
     * Render the package as PDF bytes.
     */
    public function renderPdf(Claim $claim, bool $includeNotes = false): string
    {
        return Pdf::loadHtml($this->renderHtml($claim, $includeNotes))
            ->setPaper('letter')
            ->output();
    }

    /**
     * File name for a generated package.
     */
    public function fileName(Claim $claim): string
    {
        return $claim->claim_reference.'-claim-package.pdf';
    }
}
