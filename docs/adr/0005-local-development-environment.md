# 0005. Local development: Laravel Herd + Docker services

- Status: accepted
- Date: 2026-09-29

## Decision

On Windows, run PHP and Composer through Laravel Herd and Node 22 natively; run Postgres (PostGIS), Redis and Mailpit with `docker compose up -d`. Tests use in-memory SQLite for speed; CI runs the suite on both SQLite and Postgres. Horizon (Linux only) runs in production; locally use `php artisan queue:work`.

## Consequences

- Fast local tests; Postgres-specific behaviour is still covered in CI.
- Without Docker the bootstrap falls back to SQLite.
