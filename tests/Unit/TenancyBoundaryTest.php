<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Finder\Finder;
use Tests\Support\TenancyBoundary;

/*
|--------------------------------------------------------------------------
| Tenant boundary: no silent ways around the scopes
|--------------------------------------------------------------------------
|
| Crossing tenants must go through TenantContext::bypass(), which is easy to
| find and review. Tests\Support\TenancyBoundary lists everything that
| removes isolation without it. Allow a file only with a reason.
|
*/

it('keeps app code, seeders and routes inside the tenant guards', function (): void {
    $violations = [];
    $finder = (new Finder)->files()->name('*.php')->in([app_path(), database_path('seeders'), base_path('routes')]);

    foreach ($finder as $file) {
        $relative = str_replace('\\', '/', substr((string) $file->getRealPath(), strlen(base_path()) + 1));

        if (array_key_exists($relative, TenancyBoundary::ALLOWLIST)) {
            continue;
        }

        foreach (TenancyBoundary::violations($file->getContents()) as $reason) {
            $violations[] = "{$relative}: {$reason}";
        }
    }

    expect($violations)->toBe([]);
});

it('flags each way around the tenant guards', function (string $code): void {
    expect(TenancyBoundary::violations($code))->not->toBe([]);
})->with([
    'Location::withoutGlobalScopes()->get();',
    'Location::query()->getQuery()->update([]);',
    'Location::query()->toBase()->insert([]);',
    '(new Location)->newQueryWithoutScopes()->get();',
    '(new Location)->newModelQuery()->get();',
    '(new Location)->newQueryForRestoration([1])->get();',
    '(new Location)->getConnection()->table("locations")->get();',
    'DB::connection()->table("x");',
    'Location::query()->forceDelete();',
    'Location::query()->truncate();',
    'Location::query()->updateFrom([]);',
    '$builder->expectModelInsert();',
    '$builder->expectModelWrite();',
    '$query->fromRaw("x");',
    'DB::table("locations")->get();',
    'DB::table(\'locations as l\')->get();',
    '$query->leftJoin("businesses", "a", "=", "b");',
    'DB::update("update locations set name = 1");',
    'DB::selectOne("select * from business_user");',
    'DB::affectingStatement("delete from organizations");',
    'Location::query()->withoutGlobalScopesExcept([]);',
    'Location::query()->withGlobalScope(TenantScope::class, fn () => null);',
    'Location::addGlobalScope(TenantScope::class, fn () => null);',
    '$org->businesses()->getBaseQuery()->update([]);',
    '$org->businesses()->rawUpdate(["name" => "x"]);',
    'Location::fromQuery("select * from locations");',
    'Location::query()->selectRaw("(select count(*) from businesses)");',
    'Location::query()->update(["name" => DB::raw("(select name from organizations limit 1)")]);',
    'DB::table((new Location)->getTable())->get();',
    'app("db")->select("select 1");',
]);

it('lets ordinary scoped code through', function (string $code): void {
    expect(TenancyBoundary::violations($code))->toBe([]);
})->with([
    'Location::query()->where("name", "x")->get();',
    'Location::query()->update(["name" => "x"]);',
    'DB::table("users")->get();',
    'DB::select("select 1");',
    '$context->bypass(fn () => Location::query()->get());',
]);

it('keeps soft deletes off tenant models until the guards handle them', function (): void {
    // SoftDeletes deletes through update(['deleted_at' => ...]): it would pass the update
    // rules, not the delete rules, and restore() would need the trashed row in the save query.
    $tenantModels = array_filter(
        array_map(fn (string $file): string => 'App\\Models\\'.basename($file, '.php'), glob(app_path('Models/*.php')) ?: []),
        fn (string $class): bool => is_subclass_of($class, TenantModel::class),
    );

    expect($tenantModels)->not->toBe([]);

    foreach ($tenantModels as $class) {
        expect(class_uses_recursive($class))->not->toContain(SoftDeletes::class);
    }
});
