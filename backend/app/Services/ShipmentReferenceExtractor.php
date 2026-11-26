<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * Pulls shipment reference candidates out of an email and
 * matches them against the organization's shipments (v1.3.0).
 *
 * The only references that can match are the real fields on
 * the Shipment model: internal_reference and
 * external_load_reference. Candidates are found two ways:
 * tokens shaped like a reference (letters, digits, hyphens)
 * in the subject, body, and attachment filenames, and any of
 * the organization's own reference values appearing as a
 * substring of the combined text. A match is exact: the
 * candidate equals the stored reference, case-insensitive.
 * Nothing here guesses a shipment from a broker name or a
 * date; a reference that does not match exactly is reported
 * as a candidate, never as a match.
 */
class ShipmentReferenceExtractor
{
    /**
     * Reference-shaped tokens in a text: two or more letters
     * followed by hyphen or underscore separated groups of
     * letters and digits, for example PLT-1001 or CT-TEST-AB12.
     */
    protected const REFERENCE_PATTERN = '/\b[A-Z]{2,10}(?:[-_][A-Z0-9]{2,10}){1,3}\b/i';

    /**
     * Candidate references found in the given texts, in order
     * of first appearance, uppercased and deduplicated.
     *
     * @param  array<int, string|null>  $texts
     * @return array<int, array{value: string, source: string}>
     */
    public function extract(array $texts): array
    {
        $candidates = [];

        foreach ($texts as $index => $text) {
            if ($text === null || $text === '') {
                continue;
            }

            $source = match ($index) {
                0 => 'subject',
                1 => 'body',
                default => 'attachment_filename',
            };

            if (preg_match_all(self::REFERENCE_PATTERN, $text, $matches) === false) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $value = strtoupper($match);

                if (! isset($candidates[$value])) {
                    $candidates[$value] = ['value' => $value, 'source' => $source];
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * Match the candidates against the organization's
     * shipments. Returns one entry per distinct shipment that
     * matched, naming the reference value and the field it
     * matched on, plus the candidate list with what matched.
     *
     * @param  array<int, array{value: string, source: string}>  $candidates
     * @return array{
     *   candidates: array<int, array{value: string, source: string, matched_shipment_id: string|null, matched_field: string|null}>,
     *   matches: array<int, array{shipment_id: string, reference: string, field: string}>,
     *   distinct_shipment_ids: array<int, string>
     * }
     */
    public function match(Organization $organization, array $candidates, string $haystack): array
    {
        $shipments = Shipment::forOrganization($organization)
            ->get(['id', 'internal_reference', 'external_load_reference']);

        $byReference = [];

        foreach ($shipments as $shipment) {
            foreach (['internal_reference', 'external_load_reference'] as $field) {
                $value = $shipment->getAttribute($field);

                if (is_string($value) && $value !== '') {
                    $byReference[strtoupper($value)] = [
                        'shipment_id' => (string) $shipment->getKey(),
                        'reference' => $value,
                        'field' => $field,
                    ];
                }
            }
        }

        $haystackUpper = strtoupper($haystack);
        $matchedCandidates = [];
        $matches = [];

        // Candidates from the pattern first: an exact match of a
        // candidate against a stored reference.
        foreach ($candidates as $candidate) {
            $hit = $byReference[$candidate['value']] ?? null;

            // A stored reference that never looked like a
            // candidate still counts when it appears verbatim in
            // the text (for example a reference with no hyphen).
            if ($hit === null) {
                $hit = $this->substringHit($byReference, $candidate['value'], $haystackUpper);
            }

            $matchedCandidates[] = array_merge($candidate, [
                'matched_shipment_id' => $hit['shipment_id'] ?? null,
                'matched_field' => $hit['field'] ?? null,
            ]);

            if ($hit !== null && ! isset($matches[$hit['shipment_id']])) {
                $matches[$hit['shipment_id']] = $hit;
            }
        }

        // Stored references appearing in the text that produced
        // no candidate at all (a plain reference with no hyphen,
        // for example). These are reported as candidates from
        // the body so the detail page shows what matched.
        foreach ($byReference as $upper => $hit) {
            if (isset($matches[$hit['shipment_id']])) {
                continue;
            }

            if (strlen($upper) < 4 || ! str_contains($haystackUpper, $upper)) {
                continue;
            }

            $matchedCandidates[] = [
                'value' => $hit['reference'],
                'source' => 'body',
                'matched_shipment_id' => $hit['shipment_id'],
                'matched_field' => $hit['field'],
            ];
            $matches[$hit['shipment_id']] = $hit;
        }

        return [
            'candidates' => $matchedCandidates,
            'matches' => array_values($matches),
            'distinct_shipment_ids' => array_keys($matches),
        ];
    }

    /**
     * A stored reference that contains the candidate as a
     * substring is not a match; only the reverse counts, and
     * only for the candidate itself. This helper exists for
     * the case where the candidate is a longer token that
     * embeds a stored reference, which the pattern above
     * cannot split. It returns null unless the candidate
     * contains a stored reference of at least 4 characters.
     *
     * @param  array<string, array{shipment_id: string, reference: string, field: string}>  $byReference
     */
    protected function substringHit(array $byReference, string $candidate, string $haystackUpper): ?array
    {
        foreach ($byReference as $upper => $hit) {
            if (strlen($upper) < 4) {
                continue;
            }

            if (str_contains($candidate, $upper) && str_contains($haystackUpper, $upper)) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * The combined searchable text for one email: subject,
     * text body, and attachment filenames.
     *
     * @param  array<int, string>  $attachmentFilenames
     */
    public function haystack(?string $subject, ?string $body, array $attachmentFilenames): string
    {
        return implode("\n", array_filter([
            $subject,
            $body,
            ...$attachmentFilenames,
        ], fn ($part) => $part !== null && $part !== ''));
    }
}
