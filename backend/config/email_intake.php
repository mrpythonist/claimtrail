<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound email domain
    |--------------------------------------------------------------------------
    |
    | The domain used for organization-specific inbound addresses, as
    | {org-slug}-{random6}@domain. The default is a placeholder that
    | resolves nowhere: no real mail is delivered to it. Set
    | EMAIL_INTAKE_DOMAIN to the real inbound domain once a provider
    | is wired (see docs/EMAIL-INTAKE.md); do not invent a production
    | domain here.
    |
    */

    'domain' => env('EMAIL_INTAKE_DOMAIN', 'inbound.claimtrail.local'),

    /*
    |--------------------------------------------------------------------------
    | Webhook shared secret
    |--------------------------------------------------------------------------
    |
    | The inbound parse provider signs the raw request body with
    | HMAC-SHA256 using this secret and sends it as the
    | X-Email-Intake-Signature header ("sha256=<hex>"). Requests
    | with a missing or wrong signature are rejected with 401.
    | No provider is wired yet; set EMAIL_INTAKE_WEBHOOK_SECRET
    | when one is configured.
    |
    */

    'webhook_secret' => env('EMAIL_INTAKE_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Signature header
    |--------------------------------------------------------------------------
    */

    'signature_header' => 'X-Email-Intake-Signature',

];
