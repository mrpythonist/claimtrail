<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's written note on a claim.
 *
 * Notes are append-only: there is no update or delete endpoint.
 * The review action (approve / request changes) also writes a note
 * row, so the decision and its reasoning are recorded together.
 */
class ClaimReviewNote extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'claim_id',
        'user_id',
        'note',
    ];

    /**
     * The claim this note is about.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * The member who wrote the note.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
