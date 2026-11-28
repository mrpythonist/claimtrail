<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A referral code (v2.1.0).
 *
 * Codes belong to an agency organization. Creating a partner
 * link with a code records which code brought the carrier in;
 * the use count is derived from the links that cite the code,
 * so the number can never drift from the attribution. A code
 * is attribution only: it carries no discount, no payout, and
 * no money of any kind.
 */
class ReferralCode extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'code',
        'active',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * The agency organization that issued the code.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The user who created the code.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * How many partner links cite this code. Derived, never
     * stored, so attribution and the count always agree.
     */
    public function usesCount(): int
    {
        return (int) PartnerLink::query()
            ->where('referred_by_code', $this->code)
            ->count();
    }

    /**
     * The partner links this code brought in.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PartnerLink>
     */
    public function attributedLinks()
    {
        return PartnerLink::query()
            ->where('referred_by_code', $this->code)
            ->orderByDesc('created_at')
            ->get();
    }
}
