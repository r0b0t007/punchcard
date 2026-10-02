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
