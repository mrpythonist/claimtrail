<?php

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
|
| Subscription billing for ClaimTrail (v1.2.0). The billing provider is
| behind an interface (App\Billing\BillingProvider) so the provider can
| be swapped without rewriting billing logic. The default driver is the
| mock provider: it implements the same shape as the Stripe integration
| specified in docs/validation/0.5.0-pricing-validation.md section 9.1
| (plans, monthly/annual billing, checkout, trials, proration,
| renewals, dunning, webhooks, portal, metered success-fee billing)
| against dummy data, with no real Stripe calls and no Stripe keys.
|
| To swap in real Stripe later: implement BillingProvider against the
| Stripe SDK, set BILLING_PROVIDER=stripe, supply STRIPE_SECRET_KEY
| and STRIPE_WEBHOOK_SECRET, and leave every other file alone. The
| mock's test cards (4242... succeeds, 4000...0002 declines) are the
| Stripe test card numbers, so the dummy payment methods map 1:1.
|
| Money is integer cents everywhere. Prices are hypotheses from the
| v0.5.0 pricing validation (unvalidated until v0.2.0 interviews).
|
*/

return [

    /*
    | The billing provider driver: "mock" (default) or "stripe".
    | Only "mock" is implemented in v1.2.0; "stripe" is the swap-out
    | target and is rejected at boot until an implementation lands.
    */
    'provider' => env('BILLING_PROVIDER', 'mock'),

    /*
    | Secret used to verify webhook signatures (HMAC-SHA256 over the
    | raw request body). The mock provider signs with this secret;
    | real Stripe would use its own webhook signing secret here.
    */
    'webhook_secret' => env('BILLING_WEBHOOK_SECRET', 'mock-webhook-secret'),

    /*
    | Free trial length in days. The trial is on the Growth tier and
    | needs no card (v0.5.0 section 7).
    */
    'trial_days' => 30,

    /*
    | Dunning: a failed renewal is retried after these delays, in
    | days, from the failure. After the last retry, the invoice
    | stays past due until the past-due downgrade below.
    */
    'dunning_retry_delays_days' => [3, 7],

    /*
    | Days after an invoice's due date at which an unpaid invoice
    | downgrades the organization to Free. Never deletes data.
    */
    'dunning_past_due_downgrade_days' => 14,

    /*
    | Success-fee plan: 15% of amounts recorded as paid, billed
    | monthly in arrears, capped at the Growth price ($49) per month
    | for the first year of the plan (v0.5.0 section 6.4).
    */
    'success_fee_rate' => 0.15,
    'success_fee_monthly_cap_cents' => 4900,
    'success_fee_cap_months' => 12,

    /*
    | Attribution window for the success fee, in months from the
    | claim's creation (v0.5.0 section 6.3 condition 2). Payments
    | recorded against claims created in ClaimTrail, within this
    | window, net of refunds, are the fee base.
    */
    'success_fee_attribution_months' => 12,

    /*
    | The plans, from v0.5.0 section 8. Monthly prices in USD cents;
    | annual is 10x monthly (two months free). Limits are buyer
    | units (trucks, claims, members, storage) and are shown on the
    | billing page; enforcement is v1.2.1, not this version.
    */
    'plans' => [
        'free' => [
            'name' => 'Free',
            'monthly_cents' => 0,
            'trucks' => '1 truck',
            'limits' => [
                'claims_per_month' => 5,
                'members' => 1,
                'storage' => '100 MB',
                'history_days' => 30,
                'pdf_packages' => false,
            ],
            'description' => 'One-truck owner-operator keeping a record.',
        ],
        'starter' => [
            'name' => 'Starter',
            'monthly_cents' => 1900,
            'trucks' => '1-2 trucks',
            'limits' => [
                'claims_per_month' => 20,
                'members' => 3,
                'storage' => '1 GB',
                'history_days' => null,
                'pdf_packages' => true,
            ],
            'description' => 'Owner-operator or 2-truck fleet that bills detention regularly.',
        ],
        'growth' => [
            'name' => 'Growth',
            'monthly_cents' => 4900,
            'trucks' => '3-10 trucks',
            'limits' => [
                'claims_per_month' => 100,
                'members' => 10,
                'storage' => '10 GB',
                'history_days' => null,
                'pdf_packages' => true,
            ],
            'description' => 'The 3-10 truck fleet and the small dispatcher. The default tier.',
        ],
        'fleet' => [
            'name' => 'Fleet',
            'monthly_cents' => 9900,
            'trucks' => '11-30 trucks',
            'limits' => [
                'claims_per_month' => 300,
                'members' => 25,
                'storage' => '50 GB',
                'history_days' => null,
                'pdf_packages' => true,
            ],
            'description' => 'The 11-30 truck carrier.',
        ],
        'agency' => [
            'name' => 'Agency',
            'monthly_cents' => 14900,
            'per_extra_carrier_cents' => 1200,
            'included_carriers' => 10,
            'trucks' => 'Up to 10 carriers',
            'limits' => [
                'claims_per_month' => 100,
                'claims_per_month_unit' => 'per carrier',
                'members' => null,
                'storage' => '10 GB',
                'storage_unit' => 'per carrier',
                'history_days' => null,
                'pdf_packages' => true,
            ],
            'description' => 'Dispatch agencies running claims for many carriers in one account.',
        ],
        'success_fee' => [
            'name' => 'Success Fee',
            'monthly_cents' => 0,
            'trucks' => 'Any size',
            'limits' => [
                'claims_per_month' => null,
                'members' => null,
                'storage' => null,
                'history_days' => null,
                'pdf_packages' => true,
            ],
            'description' => 'Pay 15% of what you collect, capped at $49/mo for the first year. Not listed on the pricing page; offered when price blocks a trial buyer.',
            'unlisted' => true,
        ],
    ],
];
