<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\User;
use App\Support\Auth\Impersonation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Back to the admin's own session (CHW-144): asked for, signed out, the time
 * limit reached, or either account gone. The admin is signed in again (only
 * while their account still may be: ActiveUserProvider), the owner's
 * business and confirmations are dropped, and the end goes in the audit log
 * in the admin's name, even when their account went meanwhile. The owner's
 * own sessions and remember token are never touched.
 */
final readonly class StopImpersonation
{
    public const string BACK = 'back';

    public const string EXPIRED = 'expired';

    public const string SIGNED_OUT = 'signed out';

    public const string OWNER_GONE = 'owner gone';

    public const string ADMIN_GONE = 'admin gone';

    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    public function handle(Session $session, string $why): ?Impersonation
    {
        $impersonation = Impersonation::of($session);

        if (! $impersonation instanceof Impersonation) {
            return null;
        }

        $admin = Auth::guard('web')->getProvider()->retrieveById($impersonation->adminId);
        $business = $this->context->bypass(fn (): ?Business => Business::query()->find($impersonation->businessId));

        DB::transaction(function () use ($impersonation, $session, $admin, $business, $why): void {
            Impersonation::forget($session);
            $session->forget([SetTenant::SESSION_KEY, 'auth.password_confirmed_at', 'url.intended']);

            if ($admin instanceof User && $why !== self::ADMIN_GONE) {
                Auth::guard('web')->login($admin);
                $session->regenerate();
            } else {
                // Nobody to go back to. Never logout(), which would sign the owner out everywhere.
                Auth::guard('web')->logoutCurrentDevice();
                $session->invalidate();
            }

            // Businesses are archived, never deleted: kept for a row that went another way.
            if ($business instanceof Business) {
                $this->recordAudit->handle('impersonation.ended', $business, $why, [
                    'admin_id' => $impersonation->adminId,
                    'owner_id' => $impersonation->ownerId,
                ], actor: User::query()->find($impersonation->adminId));
            }
        });

        return $impersonation;
    }
}
