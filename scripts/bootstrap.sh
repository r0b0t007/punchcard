#!/usr/bin/env bash
# One-time project bootstrap for macOS, Linux or WSL.
# Installs PHP packages that could not be resolved when the repo was scaffolded,
# prepares .env, the database and the agent tooling.
set -euo pipefail
cd "$(dirname "$0")/.."

step() { printf '\n\033[1;33m==> %s\033[0m\n' "$1"; }

step "Checking tools"
for bin in php composer node npm; do
  command -v "$bin" >/dev/null || { echo "Missing $bin. Install it first (see README)."; exit 1; }
done
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || { echo "PHP 8.3+ required"; exit 1; }

step "Dev tooling: Boost, Pest, Rector"
composer require --dev --with-all-dependencies \
  laravel/boost pestphp/pest pestphp/pest-plugin-laravel rector/rector driftingly/rector-laravel

step "App packages: Filament (admin), spatie/laravel-permission (roles)"
composer require --with-all-dependencies filament/filament spatie/laravel-permission

step "Environment"
[ -f .env ] || cp .env.example .env
php artisan key:generate --ansi

step "Publishing package config"
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --ansi
php artisan filament:install --panels --no-interaction --ansi

step "Local services (Postgres, Mailpit)"
if command -v docker >/dev/null; then
  docker compose up -d --wait
else
  echo "Docker not found. Switching .env to SQLite; install Docker later for Postgres."
  sed -i.bak 's/^DB_CONNECTION=pgsql/DB_CONNECTION=sqlite/' .env && rm -f .env.bak
  touch database/database.sqlite
fi

step "Database"
php artisan migrate --ansi
php artisan storage:link --ansi

step "Frontend"
npm install
npx playwright install chromium
php artisan wayfinder:generate --with-form --no-interaction

step "Formatting generated files"
vendor/bin/pint --parallel

step "Laravel Boost (choose Claude Code when asked)"
php artisan boost:install

step "Sanity checks"
php artisan test
npm run types:check
npm run build

printf '\n\033[1;32mDone.\033[0m Commit the changes to composer.json, composer.lock, package-lock.json and the generated Filament/Boost files.\n'
