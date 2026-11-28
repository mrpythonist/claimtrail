<?php

namespace App\Providers;

use App\Billing\BillingProvider;
use App\Billing\MockBillingProvider;
use App\Models\AgencyCarrierGrant;
use App\Models\Claim;
use App\Models\ClaimDeadline;
use App\Models\ClaimPayment;
use App\Models\CommissionRule;
use App\Models\CustomField;
use App\Models\DeadlineSetting;
use App\Models\DocumentExtraction;
use App\Models\EventCategory;
use App\Models\EvidenceFile;
use App\Models\EvidenceTemplate;
use App\Models\ExportTemplate;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\PartnerLink;
use App\Models\ReferralCode;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\WorkflowRun;
use App\Observers\AuditObserver;
use App\Observers\WebhookEventObserver;
use App\Permission;
use App\Support\Correlation;
use App\Support\CurrentOrganization;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

        // The billing provider behind its interface. Only the mock
        // is implemented in v1.2.0; "stripe" is the swap-out target
        // and is rejected until an implementation lands, so a
        // misconfigured driver fails loudly instead of silently
        // billing against nothing.
        $this->app->bind(BillingProvider::class, function () {
            return match (config('billing.provider')) {
                'mock' => new MockBillingProvider(),
                default => throw new \RuntimeException('Unknown billing provider: '.config('billing.provider')),
            };
        });

        // The OCR provider behind its interface. Only the local
        // provider is implemented in v2.0.1; "cloud" is the
        // swap-out target and is rejected until an implementation
        // lands, so a misconfigured driver fails loudly instead
        // of silently returning no text.
        $this->app->bind(\App\Services\OcrProvider::class, function () {
            return match (config('documents.ocr_provider')) {
                'local' => new \App\Services\LocalOcrProvider(),
                default => throw new \RuntimeException('Unknown OCR provider: '.config('documents.ocr_provider')),
            };
        });
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
        CustomField::observe(AuditObserver::class);
        EventCategory::observe(AuditObserver::class);
        EvidenceTemplate::observe(AuditObserver::class);
        DeadlineSetting::observe(AuditObserver::class);
        ExportTemplate::observe(AuditObserver::class);
        OrganizationMember::observe(AuditObserver::class);
        OrganizationInvitation::observe(AuditObserver::class);

        // Agency management (v2.1.0): partner links, commission
        // rules, referral codes, and carrier grants are audited
        // like the other membership-shaped records, because they
        // decide who sees a carrier and what the carrier is
        // billed.
        PartnerLink::observe(AuditObserver::class);
        CommissionRule::observe(AuditObserver::class);
        ReferralCode::observe(AuditObserver::class);
        AgencyCarrierGrant::observe(AuditObserver::class);

        // Webhook events (v2.0.2): the observer fires the
        // claim, payment, document, and workflow-run events
        // that subscribed endpoints receive. Dispatch is best
        // effort and never throws, so a webhook failure can
        // never break the operation that fired the event.
        Claim::observe(WebhookEventObserver::class);
        ClaimPayment::observe(WebhookEventObserver::class);
        DocumentExtraction::observe(WebhookEventObserver::class);
        WorkflowRun::observe(WebhookEventObserver::class);

        // Password reset links go to the frontend, which collects the
        // new password and calls the API reset endpoint.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            return $frontend.'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset());
        });

        // Slow-query logging (v1.1.1): queries slower than
        // DB_SLOW_LOG_MS are logged with their SQL and timing.
        // Bindings are never logged: they may carry personal data.
        // The threshold is read per query so tests can set it to
        // zero and verify the mechanism without a slow query.
        DB::listen(function (QueryExecuted $query): void {
            $threshold = (int) config('database.slow_log_ms', 500);

            if ($query->time < $threshold) {
                return;
            }

            Log::warning('Slow query detected.', [
                'sql' => mb_substr($query->sql, 0, 2000),
                'time_ms' => $query->time,
                'connection' => $query->connectionName,
            ]);
        });
    }
}
