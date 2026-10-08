<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the platform admin's admin panel requests in TenantContext::bypass()
 * (CHW-138): the admin works across tenants, and platform data (NFC tags)
 * reads as empty anywhere else. It wraps two routes:
 *
 * - the panel's pages (its auth middleware, after Authenticate);
 * - Livewire's update route (AppServiceProvider), where the panel's table
 *   actions, filters and modals run. Livewire's persistent middleware cannot
 *   do it: it runs against a stand-in response, before the component.
 *
 * Only for someone who may open the panel (canAccessPanel: the admin role, a
 * verified email, two-factor authentication), and on the update route only
 * when every component was rendered on a panel page: each snapshot records
 * its page, and Livewire refuses a snapshot whose checksum fails before any
 * component runs. Anyone and anything else passes through untouched. What the
 * admin may do stays with the policies, Gate::before and Invariants.
 */
final readonly class PlatformAdminWorksAcrossTenants
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getPanel('admin');
        $user = $request->user();

        if (! $user instanceof User || ! $user->canAccessPanel($panel) || ! $this->forThePanel($request, $panel)) {
            return $next($request);
        }

        return $this->context->bypass(fn (): Response => $next($request));
    }

    private function forThePanel(Request $request, Panel $panel): bool
    {
        if (! $request->routeIs('*livewire.update')) {
            return true;
        }

        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;
            $path = is_array($snapshot) ? ($snapshot['memo']['path'] ?? null) : null;

            if (! is_string($path) || ($path !== $panel->getPath() && ! str_starts_with($path, $panel->getPath().'/'))) {
                return false;
            }
        }

        return true;
    }
}
