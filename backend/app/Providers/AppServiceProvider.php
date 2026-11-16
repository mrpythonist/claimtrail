<?php

namespace App\Providers;

use App\Models\Claim;
use App\Models\ClaimDeadline;
use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Observers\AuditObserver;
use App\Permission;
use App\Support\Correlation;
use App\Support\CurrentOrganization;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentOrganization::class);
        $this->app->singleton(Correlation::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One gate per permission. Gates take the organization as the
        // second argument and delegate to the role map; no controller
        // or policy compares role names directly.
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn ($user, Organization $organization) => $user->hasPermissionIn($organization, $permission)
            );
        }

        // One audit observer serves every audited model. The
        // observer writes lifecycle rows; services that need richer
        // rows (claim transitions) write their own and suspend the
        // observer while they do.
        Shipment::observe(AuditObserver::class);
        ShipmentEvent::observe(AuditObserver::class);
        EvidenceFile::observe(AuditObserver::class);
        Claim::observe(AuditObserver::class);
        ClaimDeadline::observe(AuditObserver::class);
        OrganizationMember::observe(AuditObserver::class);
        OrganizationInvitation::observe(AuditObserver::class);

        // Password reset links go to the frontend, which collects the
        // new password and calls the API reset endpoint.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            return $frontend.'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset());
        });
    }
}
