<?php

namespace App\Providers;

use App\Models\Organization;
use App\Permission;
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

        // Password reset links go to the frontend, which collects the
        // new password and calls the API reset endpoint.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            return $frontend.'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset());
        });
    }
}
