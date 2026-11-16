<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;

/**
 * Marks a model as tenant-owned.
 *
 * Adds the organization relation, a global scope that limits queries to
 * the resolved current organization (when one is resolved), and
 * automatic organization assignment on create. The scope is defense in
 * depth: controllers must still authorize against the resolved
 * organization. Queries that legitimately span organizations (console
 * commands, invitation acceptance) use withoutGlobalScope or run with
 * no organization resolved.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                $current = app(CurrentOrganization::class);

                if ($current->check()) {
                    $builder->where(
                        $model->qualifyColumn('organization_id'),
                        $current->id()
                    );
                }
            }
        });

        static::creating(function (Model $model): void {
            if (empty($model->organization_id)) {
                $current = app(CurrentOrganization::class);

                if ($current->check()) {
                    $model->organization_id = $current->id();
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to a specific organization, regardless of the
     * resolved current organization.
     */
    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $query->withoutGlobalScope('organization')->where('organization_id', $id);
    }
}
