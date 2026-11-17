<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'onboarding_dismissed_at' => 'datetime',
        ];
    }

    /**
     * Memberships in this organization.
     */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * Active memberships only.
     */
    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active');
    }

    /**
     * Outstanding invitations for this organization.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * Pending invitations: not accepted and not expired.
     */
    public function pendingInvitations(): HasMany
    {
        return $this->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Carriers registered in this organization.
     */
    public function carriers(): HasMany
    {
        return $this->hasMany(Carrier::class);
    }

    /**
     * Shipments belonging to this organization.
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * Shipment events belonging to this organization.
     */
    public function shipmentEvents(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }

    /**
     * Evidence files belonging to this organization.
     */
    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(EvidenceFile::class);
    }

    /**
     * Claims belonging to this organization.
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * Evidence requirements configured by this organization.
     */
    public function evidenceRequirements(): HasMany
    {
        return $this->hasMany(EvidenceRequirement::class);
    }

    /**
     * Claim deadlines belonging to this organization.
     */
    public function claimDeadlines(): HasMany
    {
        return $this->hasMany(ClaimDeadline::class);
    }

    /**
     * Generated documents belonging to this organization.
     */
    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /**
     * Audit rows belonging to this organization.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Saved list views belonging to this organization.
     */
    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class);
    }

    /**
     * Notifications belonging to this organization.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Notification preferences set in this organization.
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }
}
