# punchcard

Tap-to-stamp digital loyalty cards for cafés, salons, barbers and small chains (Morocco first, FR/EN/AR).
A customer taps their phone on a battery-free NFC stamper (NTAG 424 DNA, SUN URL) or shows a member QR;
the card lives in Apple/Google Wallet and in an installable PWA. Businesses get a dashboard, push
campaigns and automations. Marketing website lives in this same app.

Full product spec, feature inventory, data model, screen IDs (C1..C14, B1..B16, S1..S3, M1) and roadmap:
`docs/spec.md`. Architecture decisions: `docs/adr/`. Backlog: Linear, team CHW, projects
"punchcard · App" and "punchcard · Marketing website".

## Stack

- Laravel 13, PHP 8.3+, PostgreSQL (SQLite in-memory for tests), Redis later for queues/Horizon
- Inertia 3 + React 19 + TypeScript, Tailwind 4, shadcn/ui (Radix), Wayfinder (typed routes/actions)
- Fortify auth (registration, email verification, 2FA, passkeys, password confirmation)
- Filament (super-admin at /admin), spatie/laravel-permission (roles: customer, staff, owner, admin)
- Pest (PHPUnit class tests also run), Larastan level 7, Pint, Rector; vite-plus (`vp`) for lint/format/build
- Playwright E2E (Chromium, iPhone WebKit, Pixel), GitHub Actions CI

## Commands

- First-time setup: `scripts/bootstrap.ps1` (Windows) or `scripts/bootstrap.sh`
- Dev server (PHP, queue, Vite): `composer dev`
- Local services: `docker compose up -d` (Postgres/PostGIS, Redis, Mailpit at :8025)
- PHP tests: `php artisan test` (single file: `php artisan test tests/Feature/...`)
- Full CI locally: `composer ci:check`
- Format PHP: `vendor/bin/pint --dirty`; static analysis: `composer analyse`; refactors: `composer refactor:check`
- Frontend: `npm run check` / `npm run check:fix`, `npm run types:check`, `npm run build`
- E2E: `npx playwright test` (starts `php artisan serve` itself)
- Regenerate typed routes after changing routes/controllers: `php artisan wayfinder:generate --with-form`

## Architecture rules

- One monolith. Surfaces: marketing site (public), customer PWA, business PWA (owner + staff), tap endpoint `/t`, Filament admin.
- Business logic lives in `app/Actions/<Domain>/*` (single `handle()` method). Controllers validate (Form Requests), authorize (Policies) and call an Action. No logic in controllers, Inertia pages or models beyond relations/casts/scopes.
- Tenancy: single database, every tenant table has `business_id` and a global scope. Customers are global users that enroll in many businesses' cards.
- Slow work (wallet pass updates, push fan-out, exports) goes to queued jobs. The tap response must stay fast.
- Money and counts are integers. Times stored in UTC, displayed in the location's timezone.
- All UI strings through translation files (fr, en, ar). Arabic is RTL: use logical Tailwind utilities (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`).

## Domain invariants (never break these)

- `stamp_events` is append-only. Never update or delete rows; corrections are new events with a `source` of `manual`/`correction`.
- `/t` verifies the SUN CMAC, then requires `counter > stampers.last_counter` inside one DB transaction with a row lock on the stamper. Replayed or copied URLs must fail.
- Cooldown and daily cap are checked per customer per card before any stamp is written.
- A completed card creates exactly one `rewards` row; redemption needs presence proof (tap or staff scan) and is idempotent.
- Tenant isolation: a user of business A can never read or change business B data. Every new tenant model gets an isolation test.
- Secrets (tag master key, Apple certificates, Google service account) come from env/paths outside the repo. Never log key material or raw SUN parameters.

## How to work in this repo

1. Pick a Linear issue (CHW-xx). Use the issue's Linear branch name (`ahmedchioua/chw-<number>-<slug>`); any branch containing `chw-<number>` links to the issue.
2. Use plan mode for anything touching stamps, rewards, auth, billing or tenancy. Wait for approval.
3. Write the failing Pest test first, then implement. Never delete or weaken a test to make it pass.
4. Run `vendor/bin/pint --dirty`, `php artisan test`, `npm run check`, `npm run types:check` before calling a task done.
5. Keep PRs small (under ~400 changed lines). Run the laravel-simplifier agent before opening a PR. Put `Fixes CHW-xx` in the PR description.
6. UI work: match the screen ID from `docs/spec.md`, use existing shadcn components, check it in Playwright (mobile-safari project) and in dark mode.

Project skills in `.claude/skills/` cover the tricky parts: `stamp-flow`, `sun-nfc-verification`, `wallet-passes`, `pwa-conventions`, `design-tokens`, `linear-workflow`. Use the `stamp-flow-reviewer` agent on any diff that touches stamping, rewards or the tap endpoint.

## Never

- Read or edit `.env`, `storage/app/private/wallet-certs/`, or any key file
- Run `migrate:fresh`, `db:wipe`, `db:seed --force` or anything against production
- Commit secrets, tag keys, `.p12`/`.pem` files, or real customer data
- Add a Composer or npm dependency without saying why in the PR description
- Use the name "PunchPass" or copy PunchPass/Wallio marketing copy or UI
