# 0005. Local development: Laravel Herd + Docker services, no Redis until needed

- Status: accepted (amended 2026-09-30: Redis removed from the MVP)
- Date: 2026-09-29

## Decision

On Windows, run PHP and Composer through Laravel Herd and Node 22 natively; run Postgres (PostGIS) and Mailpit with
`docker compose up -d`. Tests use in-memory SQLite for speed; CI runs the suite on both SQLite and Postgres.

Sessions, cache, rate limiting and queues use the `database` drivers in every environment. Production runs
`php artisan queue:work` under Supervisor (Forge daemon), with separate `wallet`, `push` and `default` queues. Replay
protection never depended on Redis: it is a Postgres row lock on the stamper.

Add Redis and Horizon (CHW-43) when any of these happens:

- push campaigns fan out tens of thousands of jobs in a burst and the `jobs` table becomes a hot spot;
- queue latency for wallet pass updates goes above 10 seconds;
- more than one app server, or cache traffic shows up in Postgres load;
- Reverb runs on more than one server.

## Consequences

- One less service to run and monitor for the pilot. Redis on the same Forge server costs nothing when it is needed.
- Code must go through `Cache`, `Queue` and `RateLimiter`, never the `Redis` facade, so switching is an `.env` change.
- No Horizon dashboard until then; failed jobs are visible in the `failed_jobs` table and Sentry.
- Without Docker the bootstrap falls back to SQLite.
