# punchcard

Tap-to-stamp digital loyalty cards for cafés, salons, barbers and small chains. Customers tap their phone on a
battery-free NFC stamper (or show a member QR) and collect stamps on a card that lives in Apple/Google Wallet
and an installable PWA. Businesses get a dashboard of regulars, push campaigns and automations.

- Product spec, research, screen inventory and design prompts: [docs/spec.md](docs/spec.md)
- Architecture decisions: [docs/adr](docs/adr)
- Backlog: Linear, team CHW, projects "punchcard · App" and "punchcard · Marketing website"
- AI agent setup: [CLAUDE.md](CLAUDE.md), [.claude/](.claude)

## Stack

Laravel 13 · Inertia 3 · React 19 + TypeScript · Tailwind 4 + shadcn/ui · Fortify (passkeys, 2FA) ·
Filament admin · PostgreSQL · Pest · Larastan · Playwright · GitHub Actions

## Getting started (Windows)

1. Install [Laravel Herd](https://herd.laravel.com) (PHP 8.3+ and Composer), Node 22, Git, and optionally Docker Desktop.
2. Clone the repo into Herd's sites folder (or link it with `herd link`).
3. Run the one-time bootstrap from PowerShell 7:

    ```powershell
    pwsh -File scripts/bootstrap.ps1
    ```

    It installs the Composer packages (Boost, Pest, Rector, Filament, permissions), creates `.env`, starts
    Postgres/Redis/Mailpit if Docker is present (falls back to SQLite otherwise), runs migrations, installs npm
    packages and Playwright, runs `boost:install` (pick **Claude Code**) and finishes with tests and a build.

4. Commit what the bootstrap generated (`composer.lock`, Filament panel provider, Boost guidelines).
5. Start developing: `composer dev`, then open the Herd URL (for example `http://punchcard.test`).

macOS, Linux and WSL: `bash scripts/bootstrap.sh`.

Troubleshooting:

- `could not find driver`: your PHP lacks `pdo_pgsql`. Herd ships it; on XAMPP or a manual PHP install, enable
  `extension=pdo_pgsql` and `extension=pgsql` in `php.ini`.
- `password authentication failed for user "punchcard"`: another Postgres already listens on 5432. Set
  `FORWARD_DB_PORT=5433` and `DB_PORT=5433` in `.env`, then `docker compose up -d`.

## Everyday commands

| Task                           | Command                                                                    |
| ------------------------------ | -------------------------------------------------------------------------- |
| Dev servers (PHP, queue, Vite) | `composer dev`                                                             |
| Local services                 | `docker compose up -d` (Mailpit inbox: http://localhost:8025)              |
| PHP tests                      | `php artisan test`                                                         |
| Everything CI runs             | `composer ci:check`                                                        |
| Format / static analysis       | `vendor/bin/pint --dirty` · `composer analyse` · `composer refactor:check` |
| Frontend checks                | `npm run check` · `npm run types:check` · `npm run build`                  |
| End-to-end tests               | `npx playwright test`                                                      |
| Regenerate typed routes        | `php artisan wayfinder:generate --with-form`                               |

## Working with Claude Code

Open the repo in Claude Code and trust the folder. The shared settings install the Laravel, Superpowers,
Context7, Playwright, GitHub, Linear and review plugins, register the Laravel Boost MCP server and add hooks
that format edited files and run the tests before Claude finishes. Start a task with the Linear issue key,
for example: `Work on CHW-12`. See the `linear-workflow` skill.

## Repository layout

```
app/Actions/        business logic, one class per use case
config/punchcard.php domain settings (tap URL, NFC keys, stamp rules, wallet)
docs/               spec and ADRs
resources/js/       Inertia React pages and components
tests/              Pest feature/unit tests, tests/e2e Playwright specs
.claude/            Claude Code settings, hooks, project skills, reviewer agent
.github/            CI/CD workflows and templates
scripts/            one-time bootstrap
```
