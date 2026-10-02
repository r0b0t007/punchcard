<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Code patterns that cross tenants without TenantContext::bypass(): dropping
 * the scopes, the base query builder (unscoped, or scoped but unguarded for
 * writes), scope-skipping writes, and raw SQL on tenant tables (ADR 0006).
 */
final class TenancyBoundary
{
    /** Tables owned by tenant models. */
    private const array TENANT_TABLES = ['organizations', 'businesses', 'locations', 'organization_user', 'business_user', 'loyalty_cards', 'card_business'];

    /**
     * Files that may use a pattern, with the reason.
     *
     * @var array<string, string>
     */
    public const array ALLOWLIST = [
        'app/Support/Tenancy/TenantBuilder.php' => 'defines the guarded forceDelete()/delete() through toBase(), and checks in bypass() whether a missed row still exists',
        'app/Models/Concerns/GuardsTenantWrites.php' => 'announces a model insert or save, which TenantBuilder then checks',
        'app/Models/Concerns/BelongsToBusiness.php' => 'registers the tenant scope of site data',
        'app/Models/Concerns/BelongsToOrganization.php' => 'registers the tenant scope of program data',
        'app/Models/Business.php' => 'registers its own tenant scope',
        'app/Models/Organization.php' => 'registers its own tenant scope',
        'app/Models/OrganizationMember.php' => 'registers its own tenant scope',
    ];

    /**
     * @return array<string, string> regex => reason
     */
    public static function patterns(): array
    {
        $tables = implode('|', self::TENANT_TABLES);
        $table = "['\"]({$tables})(\\s+as\\s+\\w+)?['\"]";

        return [
            '/withoutGlobalScope(s|sExcept)?\s*\(/' => 'drops global scopes',
            '/(with|add)GlobalScope\(\s*(TenantScope::class|new\s+TenantScope)/' => 'replaces the tenant scope',
            '/(->|::)(getQuery|getBaseQuery|toBase)\s*\(/' => 'base query builder: unscoped, or without the write guards',
            '/(->|::)(rawUpdate|fromQuery)\s*\(/' => 'write or read around the scope',
            '/->(newPivotStatement|newPivotStatementForId|newPivotQuery)\s*\(/' => 'raw pivot query: skips the membership guards',
            '/app\(\s*[\'"]db[\'"]\s*\)/' => 'raw connection',
            '/(->|::)(newQueryWithoutScopes|newModelQuery|newQueryForRestoration)\s*\(/' => 'unscoped model query',
            '/->getConnection\s*\(\s*\)/' => 'raw connection',
            '/DB::connection\s*\(/' => 'raw connection',
            '/(->|::)(forceDelete|truncate|updateFrom)\s*\(/' => 'write that skips the scope',
            '/->(expectModelInsert|expectModelWrite)\s*\(/' => 'announces a model write',
            '/->(fromSub|fromRaw)\s*\(/' => 'raw FROM',
            "/DB::table\\(\\s*{$table}/" => 'raw query on a tenant table',
            "/->(table|from|join|leftJoin|rightJoin|crossJoin)\\(\\s*{$table}/" => 'raw query on a tenant table',
            "/DB::(select|selectOne|scalar|cursor|selectResultSets|update|insert|delete|statement|affectingStatement|unprepared)\\([^;]*\\b({$tables})\\b/s" => 'raw SQL on a tenant table',
            "/(Raw|DB::raw)\\([^;]*\\b({$tables})\\b/s" => 'raw SQL on a tenant table',
            '/DB::table\(\s*\(?\s*new\s/' => 'raw query on a model table',
        ];
    }

    /**
     * @return list<string> the reasons the code crosses the boundary
     */
    public static function violations(string $code): array
    {
        $reasons = [];

        foreach (self::patterns() as $pattern => $reason) {
            if (preg_match($pattern, $code) === 1) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }
}
