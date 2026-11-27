<?php

/*
|--------------------------------------------------------------------------
| Communication integrations
|--------------------------------------------------------------------------
|
| Channel integrations for ClaimTrail (v1.3.3). A channel is a
| Slack workspace, a Microsoft Teams team, or the mock channel,
| connected by a webhook URL. The dispatcher
| (App\Comms\ChannelDispatcher) sends event messages through the
| provider behind the App\Comms\ChannelProvider interface, so a
| provider is a swap, not a rewrite. The default driver is the
| mock channel: it records the message locally with no live
| delivery, so tests, smoke runs, and channel tests can verify
| the flow end to end.
|
| Slack and Teams are schema-only in v1.3.3, the same way the
| Trimble TMS provider was schema-only in v1.3.2: their payload
| shapes are modelled on the vendors' published incoming-webhook
| formats (Slack Block Kit message, Teams MessageCard), the
| webhook URL shape is validated, and the payload is recorded
| in the delivery history — but no live HTTP call is made and
| no message ever leaves this app. Live delivery needs a real
| Slack workspace with an incoming webhook (or a Slack app),
| or a real Teams team with an Office 365 connector webhook
| (or a Workflow webhook), and a mailer that can reach the
| driver. Until then every send is recorded as logged, never
| as sent, and the delivery history says so.
|
| To wire a live provider later: implement ChannelProvider
| against the vendor's webhook API, register the driver in
| the registry, and leave every other file alone.
|
*/

return [

    /*
    | The default provider driver for new channels: "mock".
    | Only "mock", "slack", and "teams" are implemented in
    | v1.3.3; no live delivery exists for any of them yet.
    */
    'default_provider' => env('COMMS_PROVIDER', 'mock'),

    /*
    | Provider configuration. live_available is false for
    | every provider in v1.3.3: the mock is local-only by
    | design, and Slack and Teams are schema-only until a
    | live workspace or team is connected.
    */
    'providers' => [
        'mock' => [
            'label' => 'Mock channel',
            'live_available' => false,
        ],
        'slack' => [
            'label' => 'Slack',
            'live_available' => false,
            'webhook_host' => 'hooks.slack.com',
            'webhook_path_prefix' => '/services/',
        ],
        'teams' => [
            'label' => 'Microsoft Teams',
            'live_available' => false,
            'webhook_hosts' => [
                'outlook.office.com',
                'outlook.office365.com',
            ],
            'webhook_path_prefix' => '/webhook/',
        ],
    ],

    /*
    | Driver evidence links: how long a new link stays usable
    | (hours), and how many submissions it accepts by default.
    | A link with max_uses 1 is single-use: one evidence file
    | and it is spent. The per-link values always win over
    | these defaults.
    */
    'driver_link_ttl_hours' => 72,
    'driver_link_max_uses' => 1,

    /*
    | How long the comms:expire-links command keeps looking
    | back for links that expired without an expiry notice.
    */
    'driver_link_expiry_notice_days' => 30,

];
