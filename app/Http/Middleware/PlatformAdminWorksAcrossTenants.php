<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PlatformRole;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Filament admin panel runs a platform admin's request in
 * TenantContext::bypass() (CHW-138): the admin works across tenants, and
 * platform data (NFC tags) reads as empty anywhere else. Listed after
 * Authenticate, which already refuses anyone who may not open the panel, and
 * persistent, so the panel's Livewire updates (table actions) run in it too;
 * it still checks the role itself, and lets anyone else through untouched.
 * What the admin may do stays with the policies, Gate::before and
 * App\Policies\Invariants.
 */
final readonly class PlatformAdminWorksAcrossTenants
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasRole(PlatformRole::Admin->value)) {
            return $next($request);
        }

        return $this->context->bypass(fn (): Response => $next($request));
    }
}
