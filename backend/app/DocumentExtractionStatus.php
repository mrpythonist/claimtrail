<?php

namespace App;

/**
 * Lifecycle of one document extraction (v2.0.1).
 *
 * pending: created, not yet processed. processing: the provider,
 * classifier, and field extractor are running (synchronous in
 * this version, so the state is brief). completed: processed
 * with no open questions. needs_verification: a human must
 * look — unclassified document, a low-confidence field, an
 * unmatched reference candidate, or a duplicate. verified: a
 * human reviewed every open question. rejected: a human decided
 * the extraction is not usable. failed: no text could be
 * extracted (a scanned image has no text layer for the local
 * provider) or the stored file failed its checksum.
 */
enum DocumentExtractionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case NeedsVerification = 'needs_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::NeedsVerification => 'Needs verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    /**
     * Every status value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
