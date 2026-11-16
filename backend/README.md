# ClaimTrail backend

Laravel 13 API for ClaimTrail. Sanctum token authentication, multi-tenant
organizations, role-based access control. See the root `README.md` and
`docs/` for the full picture.

## Quickstart

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # SQLite by default; see .env.example for PostgreSQL
php artisan serve            # http://localhost:8000
```

API base URL: `http://localhost:8000/api/v1`. Endpoint docs are in
`../docs/API.md` and `../docs/openapi.yaml`.

## Tests

```sh
php artisan test             # PHPUnit, SQLite in-memory
```

## Layout

- `app/Http/Controllers/Api/V1/` - API controllers
- `app/Models/` - Eloquent models, plus `Concerns/BelongsToOrganization`
- `app/Policies/` - authorization policies
- `app/Support/CurrentOrganization.php` - request-scoped tenant holder
- `config/permissions.php` - the single role to permission map
- `routes/api.php` - all API routes
- `tests/Feature/` - HTTP-level tests
