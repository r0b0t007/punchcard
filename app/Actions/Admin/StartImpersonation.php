<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\BusinessRole;
use App\Enums\PlatformRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use App\Support\Auth\Impersonation;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The platform admin signs in as a business owner to support them (CHW-144):
 * an owner of that business, never an anonymised account, another platform
 * admin or themselves, at a business that operates (not suspended, nothing
 * archived). The entry in the audit log is written first, as the admin, in
 * the transaction the session switches in: no entry, no switch.
 *
 * The session becomes the owner's, in their business, for a fixed time
 * (punchcard.admin.impersonation_minutes), read-only (WhileImpersonating).
 * Nothing the admin confirmed carries over. StopImpersonation goes back.
 */
final readonly class StartImpersonation
{
    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    public function handle(User $admin, Business $business, User $owner, Session $session): Impersonation
    {
        $this->assertAllowed($admin, $business, $owner, $session);

        return DB::transaction(function () use ($admin, $business, $owner, $session): Impersonation {
            // Their row under KEY SHARE: an account deletion either waits for this, or ran first and this refuses.
            if (! User::query()->whereKey($owner->id)->notAnonymised()->lock('for key share')->exists()) {
                throw new ImpersonationRefused(__('That account was deleted.'));
            }

            $impersonation = new Impersonation($admin->id, $owner->id, $business->id, now()->toImmutable()->addMinutes((int) config('punchcard.admin.impersonation_minutes')));
            $this->recordAudit->handle('impersonation.started', $business, context: [
                'admin_id' => $admin->id,
                'owner_id' => $owner->id,
                'ends_at' => $impersonation->endsAt->toIso8601String(),
            ], actor: $admin);

            Auth::guard('web')->login($owner);
            $session->regenerate();
            $session->forget(['auth.password_confirmed_at', 'url.intended']);
            $session->put(SetTenant::SESSION_KEY, 'business:'.$business->id);
            $impersonation->keep($session);

            return $impersonation;
        });
    }

    private function assertAllowed(User $admin, Business $business, User $owner, Session $session): void
    {
        if (! $admin->canAccessPanel(Filament::getPanel('admin'))) {
            throw new ImpersonationRefused(__('Only the platform admin signs in as an owner.'));
        }

        if (Impersonation::of($session) instanceof Impersonation) {
            throw new ImpersonationRefused(__('You are already signed in as an owner: go back to admin first.'));
        }

        [$owns, $operating] = $this->context->bypass(fn (): array => [
            BusinessMember::query()->where('business_id', $business->id)->where('user_id', $owner->id)->where('role', BusinessRole::Owner)->exists(),
            Business::query()->whereKey($business->id)->operating()->exists(),
        ]);

        match (true) {
            ! $owns => throw new ImpersonationRefused(__('That person is not an owner of this business.')),
            $owner->is($admin), $owner->hasRole(PlatformRole::Admin->value) => throw new ImpersonationRefused(__('You can\'t sign in as a platform admin, or as yourself.')),
            $owner->isAnonymised() => throw new ImpersonationRefused(__('That account was deleted.')),
            ! $operating => throw new ImpersonationRefused(__('This business is suspended or closed: its owner sees nothing in it.')),
            default => null,
        };
    }
}
