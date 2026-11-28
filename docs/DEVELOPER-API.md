# Developer API

The public REST API for ClaimTrail (v2.0.2). A machine-readable
definition is in `docs/openapi-public.yaml`; the internal API
the frontend uses is a separate surface, documented in
`docs/openapi.yaml` and `docs/API.md`.

## The two surfaces

| Surface | Base URL | Auth | For |
| --- | --- | --- | --- |
| Public API | `/api/public/v1` | Bearer API key | Other systems: TMS, brokers, accounting tools |
| Internal API | `/api/v1` | Sanctum user token | The ClaimTrail frontend |

Never send a user session token to the public API, and never
send an API key to the internal API. They are different
credentials for different callers.

## Getting a key

Keys are created in the Developers page (`/developers`) or
through the internal API (`POST /api/v1/api-keys`). Managing
keys needs `manage-api-keys` (owner, admin, operations
manager, dispatcher) and the Starter plan or better: the Free
plan is refused with a 402.

```bash
curl -X POST http://localhost:8000/api/v1/api-keys \
  -H "Authorization: Bearer <user token>" \
  -H "X-Organization-Id: <organization uuid>" \
  -H "Content-Type: application/json" \
  -d '{"name": "TMS sync key", "scopes": ["claims:read", "claims:write"]}'
```

The response carries the full key exactly once, in
`plain_text_key`. Store it somewhere safe: the app cannot show
it to you again, and it is stored hashed (SHA-256), so even the
database never holds the full key.

```json
{
  "data": {
    "id": "01a21ff6-aace-7343-8e37-de51206495ad",
    "name": "TMS sync key",
    "kind": "standard",
    "key_prefix": "ct_live_RT0D",
    "scopes": ["claims:read", "claims:write"],
    "status": "active"
  },
  "plain_text_key": "ct_live_RT0DKH5U30LAcjejJR85E2gvT9ik3MKQbvoSdERz"
}
```

A later `GET /api/v1/api-keys/<id>` returns the same row
without `plain_text_key`: only the prefix, which is how you
tell keys apart.

## Scopes

| Scope | Allows |
| --- | --- |
| `claims:read` | List and read claims |
| `claims:write` | Create claims |
| `shipments:read` | List and read shipments |
| `payments:read` | List recorded payments |
| `documents:read` | List processed document extractions |
| `broker:status` | Broker claim status (broker keys only) |

Standard keys may not carry `broker:status`. Broker keys must
name the broker and may only carry `claims:read` and
`broker:status`: they read claims and check broker status,
nothing else. A key with the wrong scope gets 403.

## Calling the public API

```bash
curl http://localhost:8000/api/public/v1/claims \
  -H "Authorization: Bearer ct_live_RT0DKH5U30LAcjejJR85E2gvT9ik3MKQbvoSdERz"
```

```bash
curl http://localhost:8000/api/public/v1/ \
  -H "Authorization: Bearer ct_live_RT0DKH5U30LAcjejJR85E2gvT9ik3MKQbvoSdERz"
```

The index answers with the key's identity, scopes, and the
rate limit that applies to it:

```json
{
  "data": {
    "service": "ClaimTrail Public API",
    "version": "v1",
    "app_version": "2.0.2",
    "organization_id": "01a21ff6-9af0-77ef-aa5f-7b0bcc16d505",
    "key_id": "01a21ff6-aace-7343-8e37-de51206495ad",
    "key_kind": "standard",
    "scopes": ["claims:read", "claims:write"],
    "rate_limit_per_minute": 120
  }
}
```

### Errors

| Status | Meaning |
| --- | --- |
| `401` | Missing, unknown, revoked, or expired key |
| `402` | The organization is on the Free plan (`plan_limit_exceeded`) |
| `403` | The key lacks the scope the endpoint needs |
| `404` | Unknown id, including an id from another organization |
| `422` | Validation failed (Laravel's standard shape) |
| `429` | Over the per-key rate limit; retry after `Retry-After` seconds |

A key from another organization sees nothing cross-tenant:
unknown ids answer 404, never 403, so organization ids and
claim ids cannot be probed.

### Rate limits

120 requests per minute per key by default (config
`public_api.rate_limit_per_minute`). The limit is per key, not
per organization, so one noisy integration cannot starve
another key on the same account. A key over the limit gets
429 with a `Retry-After` header in seconds.

### Creating a claim

```bash
curl -X POST http://localhost:8000/api/public/v1/claims \
  -H "Authorization: Bearer <key with claims:write>" \
  -H "Content-Type: application/json" \
  -d '{"shipment_id": "<shipment uuid>", "claim_category": "detention", "requested_amount": 450.00, "currency": "USD"}'
```

The monthly claim cap still applies: above it the creation is
refused with 402. The created claim starts in `potential`
status, exactly as a claim created in the app does.

## Rotation and revocation

Rotate a key when it may have leaked, or on a schedule. The
old key keeps working for a 24 hour grace window
(`public_api.rotation_grace_hours`), then stops; the
replacement works immediately.

```bash
curl -X POST http://localhost:8000/api/v1/api-keys/<key id>/rotate \
  -H "Authorization: Bearer <user token>" \
  -H "X-Organization-Id: <organization uuid>"
```

The response carries the replacement full key exactly once,
in `plain_text_key`, and the old key row as `rotated_from`
with status `rotating`. Update the integration to the new key
inside the window; the old key stops working when the window
closes.

Revoke a key to kill it at once. Revocation is immediate and
permanent: there is no un-revoke.

```bash
curl -X DELETE http://localhost:8000/api/v1/api-keys/<key id> \
  -H "Authorization: Bearer <user token>" \
  -H "X-Organization-Id: <organization uuid>"
```

A revoked key gets 401 on its next call.

## Webhooks

Register an endpoint in the Developers page or through the
internal API (`POST /api/v1/webhooks`). Managing endpoints
needs `manage-integrations` and the Starter plan or better.

```bash
curl -X POST http://localhost:8000/api/v1/webhooks \
  -H "Authorization: Bearer <user token>" \
  -H "X-Organization-Id: <organization uuid>" \
  -H "Content-Type: application/json" \
  -d '{"name": "Ops hook", "url": "https://example.com/claimtrail-hooks", "events": ["claim.created", "claim.paid"]}'
```

The response carries the signing secret exactly once, in
`plain_text_secret`. It is stored encrypted and never returned
again. An empty events list means every event type.

### Event types

| Event | Fires when |
| --- | --- |
| `claim.created` | A claim is created |
| `claim.updated` | A claim changes (including a status change) |
| `claim.paid` | A claim moves to paid |
| `payment.recorded` | A payment is recorded on a claim |
| `document.processed` | A document extraction completes or is verified |
| `workflow.run_completed` | A workflow run completes or fails |

The payload carries only ids, references, statuses, and
amounts: never tokens, secrets, or personal contact details.

```json
{
  "event": "claim.created",
  "organization_id": "01a21ff6-9af0-77ef-aa5f-7b0bcc16d505",
  "data": {
    "claim_id": "01a21ffa-d420-7bc6-bc3a-8f8269e9dca2",
    "claim_reference": "CLM-2026-0001",
    "status": "potential",
    "requested_amount": "450.00"
  }
}
```

### Verifying the signature

Every delivery is a real HTTP POST signed with HMAC-SHA256
over the raw JSON body, using the endpoint's secret. The
signature travels in `X-ClaimTrail-Signature` as
`sha256=<hex>`, with `X-ClaimTrail-Event`,
`X-ClaimTrail-Delivery-Id`, and `X-ClaimTrail-Timestamp`
alongside it. Verify before trusting the payload, and reject
replays by checking the timestamp is recent.

```python
import hashlib
import hmac

def verify(secret: str, timestamp: str, body: bytes, signature: str) -> bool:
    expected = "sha256=" + hmac.new(
        secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected, signature)
```

### Retries and the delivery log

A 2xx response delivers. Anything else is a failed attempt:
the delivery retries with backoff, at most 5 attempts
(`public_api.webhook_max_attempts`, delays 60, 300, 900, 1800
seconds by default), then is recorded `failed` and never
retried again. A disabled endpoint receives nothing. Every
attempt is in the delivery log (`GET
/api/v1/webhooks/<id>/deliveries`), which is the audit trail:
nothing is only logged.

Send a test delivery any time (`POST
/api/v1/webhooks/<id>/test`): it delivers a `webhook.test`
event immediately, so you can prove the endpoint works
before a real event fires.

## Accounting integrations

Connect a provider in the Developers page or through the
internal API (`POST /api/v1/accounting`). Only mock providers
exist: QuickBooks and Xero modelled schema-only. There are no
live credentials, no real sync, and no money moves. A sync
exports recorded payments to the provider's dummy shape and
returns deterministic dummy ids (`QB-MOCK-*`,
`XERO-MOCK-*`); a zero-amount payment is skipped, never
exported. A real provider is a later swap.

```bash
curl -X POST http://localhost:8000/api/v1/accounting \
  -H "Authorization: Bearer <user token>" \
  -H "X-Organization-Id: <organization uuid>" \
  -H "Content-Type: application/json" \
  -d '{"provider": "mock_quickbooks", "name": "QB mock", "settings": {"company_name": "Acme Carriers LLC"}}'
```

## Authorized broker integrations

Brokers are API consumers: a broker gets a broker-scoped key
(`claims:read` + `broker:status`, naming the broker) and may
read claims and check the status of a claim from the broker's
side:

```bash
curl http://localhost:8000/api/public/v1/broker/claims/<claim id>/status \
  -H "Authorization: Bearer <broker key>"
```

```json
{
  "data": {
    "broker_name": "Alpha Brokers",
    "broker_reference": "BRK-MOCK-E2E7F3EB26",
    "claim_reference": "CLM-2026-0001",
    "claim_status": "accepted",
    "broker_status": "approved",
    "requested_amount": "450.00",
    "accepted_amount": "450.00",
    "recovered_amount": "250.00",
    "currency": "USD",
    "live_available": false,
    "note": "Mock broker view. Derived only from the claim as recorded in ClaimTrail; no broker system is contacted."
  }
}
```

The broker status is modelled from the claim as recorded in
ClaimTrail; no broker system is contacted, and
`live_available` is always false in this version.

This version does **not** send claim packages to a broker.
There is no submission channel: a broker integration is
read/status access only, and a claim marked submitted in
ClaimTrail is a hand-recorded status, not a sent package.
That gap is documented in
`docs/PRODUCTION-LAUNCH-CHECKLIST.md` and was ranked first
among the roadmap proposals in v0.4.0.
