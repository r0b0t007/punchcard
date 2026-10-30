<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Tenancy\ResolveTenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the current tenant for business and organization routes (alias
 * `tenant`) from the signed-in user, and clears it for guests. Registered
 * before SubstituteBindings, so route model bindings ({location}, {business})
 * resolve inside the tenant and another tenant's id is a 404.
 *
 * A user with several memberships picks one in the tenant switcher; the choice
 * ("org:5" or "business:7") is kept under SESSION_KEY and only honoured if
 * they still belong to it.
 */
final readonly class SetTenant
{
    public const string SESSION_KEY = 'tenant';

    /** A choice as kept under SESSION_KEY: "business:7" or "org:5". */
    public const string CHOICE = '/^(org|business):([1-9]\d*)$/';

    /** The choice that works in one business, as kept under SESSION_KEY. */
    public static function businessChoice(int $businessId): string
    {
        return 'business:'.$businessId;
    }

    /** The choice that works across an organization (its org admin's network), as kept under SESSION_KEY. */
    public static function organizationChoice(int $organizationId): string
    {
        return 'org:'.$organizationId;
    }

    /**
     * A choice read back: what it names and its id, or [null, 0] for anything else.
     *
     * @return array{0: 'org'|'business'|null, 1: int}
     */
    public static function parseChoice(?string $choice): array
    {
        if ($choice === null || preg_match(self::CHOICE, $choice, $matches) !== 1) {
            return [null, 0];
        }

        return [$matches[1] === 'org' ? 'org' : 'business', (int) $matches[2]];
    }

    public function __construct(
        private ResolveTenant $resolveTenant,
        private TenantContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $choice = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

            $this->resolveTenant->handle($user, is_string($choice) ? $choice : null);
        } else {
            $this->context->clear();
        }

        return $next($request);
    }
}
