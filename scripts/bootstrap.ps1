# One-time project bootstrap for Windows (PowerShell 7+, Laravel Herd recommended).
# Installs PHP packages that could not be resolved when the repo was scaffolded,
# prepares .env, the database and the agent tooling.
#   pwsh -File scripts/bootstrap.ps1
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')

function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Yellow }
function Run($cmd) {
    Write-Host "  $cmd" -ForegroundColor DarkGray
    Invoke-Expression $cmd
    if ($LASTEXITCODE -ne 0) { throw "Failed: $cmd" }
}

Step 'Checking tools'
foreach ($bin in 'php', 'composer', 'node', 'npm') {
    if (-not (Get-Command $bin -ErrorAction SilentlyContinue)) {
        throw "Missing $bin. Install Laravel Herd (php + composer) and Node 22, see README."
    }
}
Run 'php -r "exit(version_compare(PHP_VERSION, ''8.3.0'', ''>='') ? 0 : 1);"'

Step 'Dev tooling: Boost, Pest, Rector'
Run 'composer require --dev --with-all-dependencies laravel/boost pestphp/pest pestphp/pest-plugin-laravel rector/rector driftingly/rector-laravel'

Step 'App packages: Filament (admin), spatie/laravel-permission (roles)'
Run 'composer require --with-all-dependencies filament/filament spatie/laravel-permission'

Step 'Environment'
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
Run 'php artisan key:generate --ansi'

Step 'Publishing package config'
Run 'php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --ansi'
Run 'php artisan filament:install --panels --no-interaction --ansi'

Step 'Local services (Postgres, Mailpit)'
if (Get-Command docker -ErrorAction SilentlyContinue) {
    Run 'docker compose up -d --wait'
} else {
    Write-Host 'Docker not found. Switching .env to SQLite; install Docker Desktop later for Postgres.'
    (Get-Content .env) -replace '^DB_CONNECTION=pgsql', 'DB_CONNECTION=sqlite' | Set-Content .env
    if (-not (Test-Path database/database.sqlite)) { New-Item database/database.sqlite -ItemType File | Out-Null }
}

Step 'Database'
Run 'php artisan migrate --ansi'
Run 'php artisan storage:link --ansi'

Step 'Frontend'
Run 'npm install'
Run 'npx playwright install chromium'
Run 'php artisan wayfinder:generate --with-form --no-interaction'

Step 'Formatting generated files'
Run 'vendor/bin/pint --parallel'

Step 'Laravel Boost (choose Claude Code when asked)'
php artisan boost:install

Step 'Sanity checks'
Run 'php artisan test'
Run 'npm run types:check'
Run 'npm run build'

Write-Host "`nDone. Commit composer.json, composer.lock, package-lock.json and the generated Filament/Boost files." -ForegroundColor Green
