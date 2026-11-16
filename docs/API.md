# API

Base URL: `http://localhost:8000/api/v1` in development. All endpoints return
JSON. A machine-readable definition is in `docs/openapi.yaml`.

## Authentication

Sanctum personal access tokens (Bearer). Register or log in to receive a
token, then send it on every authenticated request:

```text
Authorization: Bearer <token>
```

Tokens do not expire by default. Set `SANCTUM_TOKEN_EXPIRATION` (minutes)
once the frontend has a token refresh flow.

## Tenancy

Tenant-scoped endpoints require the organization header:

```text
X-Organization-Id: <organization uuid>
```

The backend verifies the organization exists and the user is an active
member. Responses:

- Missing header: `400` with "An organization must be selected."
- Unknown organization, or the user is not a member: `404`. The 404 is
  deliberate, so organization ids cannot be probed.
- Inactive member: `404`, same as a non-member.

## Errors

Validation failures return `422` with Laravel's standard shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

Other errors return `{"message": "..."}` with the appropriate status:
`400` (missing organization header), `401` (missing or invalid token),
`403` (authenticated but not permitted), `404` (not found or not a member),
`429` (rate limited).

## Rate limits

- Register: 10 requests per minute per IP.
- Login, forgot password, reset password: 5 per minute per IP.
- Resend verification email: 3 per minute per user.

## Endpoints

### Auth

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| POST | `/auth/register` | No | Register. Body: `name`, `email`, `password`, `password_confirmation` (min 8 chars). Returns `user` and `token` (201). Sends a verification email. |
| POST | `/auth/login` | No | Log in. Body: `email`, `password`. Returns `user` and `token`. Wrong credentials return 422 with a generic message. |
| POST | `/auth/logout` | Yes | Revokes the token used for this request. |
| GET | `/auth/me` | Yes | The authenticated user with their organizations and roles. |
| PUT | `/auth/profile` | Yes | Update display name. Body: `name`. |
| PUT | `/auth/password` | Yes | Change password. Body: `current_password`, `password`, `password_confirmation`. |
| POST | `/auth/forgot-password` | No | Send a reset link. Always returns the same success message, even for unknown emails. |
| POST | `/auth/reset-password` | No | Reset with the emailed token. Body: `token`, `email`, `password`, `password_confirmation`. |

### Email verification

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/email/verify/{id}/{hash}` | Signed URL | Verifies the address and redirects to the frontend `/verify-email` page with `?status=verified` or `?status=invalid`. |
| POST | `/email/verification-notification` | Yes | Resends the verification email. |

### Organizations

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/organizations` | Yes | Organizations the user belongs to, with their role and status in each. |
| POST | `/organizations` | Yes | Create an organization. Body: `name`, optional `timezone`. The creator becomes owner (201). |
| GET | `/organizations/{organization}` | Yes | One organization. Members only (403 otherwise). |
| PUT | `/organizations/{organization}` | Yes | Update `name`, `timezone`, `settings`. Requires `manage-organization`. |
| POST | `/organizations/{organization}/switch` | Yes | Validates membership and returns the organization. The frontend stores the choice and sends it in the header afterwards. |

### Dashboard (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/dashboard` | Yes + org header | Organization overview: member counts, pending invitations, current role, member since. No shipment or claim figures exist yet. |

### Members (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/members` | Yes + org header | Paginated members (25 per page). Query: `search`, `role`, `status`, `page`. Requires `view-members`. |
| PUT | `/members/{member}` | Yes + org header | Change `role` or `status`. Requires `manage-members`. Owner protections apply (see below). |
| DELETE | `/members/{member}` | Yes + org header | Remove a member. Requires `manage-members`. Owner protections apply. |

Owner protections: only an owner can change another owner's membership or
grant the owner role, and the last active owner cannot be demoted,
deactivated, or removed.

### Invitations (tenant-scoped, except accept)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/invitations` | Yes + org header | Paginated invitations. Requires `manage-invitations`. |
| POST | `/invitations` | Yes + org header | Invite an email address. Body: `email`, `role`. Only an owner can invite an owner. Sends the invitation email (201). |
| POST | `/invitations/accept` | Yes, no org header | Accept with the token from the email. Body: `token`. The invitation must have been sent to the signed-in user's email. |
| DELETE | `/invitations/{invitation}` | Yes + org header | Revoke a pending invitation. Requires `manage-invitations`. |

## Example: register, create an organization, invite someone

```sh
API=http://localhost:8000/api/v1

# Register and capture the token
TOKEN=$(curl -s -X POST $API/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"password123","password_confirmation":"password123"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"])')

# Create an organization
ORG=$(curl -s -X POST $API/organizations \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Acme Trucking Co.","timezone":"America/Chicago"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["id"])')

# Invite a dispatcher
curl -s -X POST $API/invitations \
  -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" \
  -H 'Content-Type: application/json' \
  -d '{"email":"dispatcher@example.com","role":"dispatcher"}'
```
