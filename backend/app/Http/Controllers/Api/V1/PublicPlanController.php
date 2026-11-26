<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\PlanCatalog;
use App\Http\Controllers\Controller;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;

/**
 * The public plan catalog (v1.2.2).
 *
 * The marketing pricing page reads its numbers from here, so the
 * page and the billing system cannot drift apart: the catalog is
 * the v0.5.0 section 8 tiers, served from config/billing.php. The
 * success-fee plan is unlisted by design (v0.5.0 section 6.4) and
 * is not returned. No authentication: this is public marketing
 * data, and the endpoint is rate-limited like the other public
 * endpoints.
 */
class PublicPlanController extends Controller
{
    /**
     * The listed plans, in display order, with the trial offer.
     */
    public function index(BillingService $billing): JsonResponse
    {
        $plans = array_values(array_filter(
            $billing->plans(),
            fn (array $plan) => $plan['listed'] && $plan['key'] !== PlanCatalog::SUCCESS_FEE,
        ));

        return response()->json(['data' => [
            'plans' => $plans,
            'trial' => [
                'plan' => PlanCatalog::GROWTH,
                'days' => (int) config('billing.trial_days', 30),
                'requires_card' => false,
            ],
        ]]);
    }
}
