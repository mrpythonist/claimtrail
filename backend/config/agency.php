<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agency management defaults (v2.1.0)
    |--------------------------------------------------------------------------
    |
    | Defaults for the partner ecosystem surface. Everything here is a
    | default an agency organization may override through the API;
    | nothing here is a measured production value.
    |
    | - Referral codes: the prefix and the length of the random part.
    |   Codes are attribution records only: creating a partner link
    |   with a code records which code brought the carrier in, and
    |   the code's use count is derived from the links that cite it.
    | - Commission: the default rate applied when an agency has no
    |   rule for a carrier. Earned commission is computed only from
    |   recorded ClaimPayment rows (the money the carrier actually
    |   recorded as received), never from requested or accepted
    |   amounts. Commission is a recorded and calculated figure:
    |   billing runs on the mock provider, no real money moves, and
    |   nothing here pays a partner out.
    |
    */

    'referral_code_prefix' => env('AGENCY_REFERRAL_PREFIX', 'REF'),

    'referral_code_length' => (int) env('AGENCY_REFERRAL_CODE_LENGTH', 8),

    'default_commission_rate_percent' => (float) env('AGENCY_DEFAULT_COMMISSION_RATE', 10),

    'commission_basis' => 'paid',
];
