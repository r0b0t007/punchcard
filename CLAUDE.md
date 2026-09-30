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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
