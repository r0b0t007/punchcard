<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Http\Middleware\SetTenant;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Support\Auth\Impersonation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Back to the admin's own session (CHW-144): asked for, signed out, the time
 * limit reached, refused pages in a row, or either account gone. The admin
 * is signed in again first (only while their account still may be:
 * ActiveUserProvider), so a failure after leaves them themselves; then the
 * end goes in the audit log in the admin's name, even when their account
 * went meanwhile. The owner's own sessions and remember token are never
 * touched.
 */
final readonly class StopImpersonation
{
    public const string BACK = 'back';

    public const string EXPIRED = 'expired';

    public const string SIGNED_OUT = 'signed out';

    public const string REFUSED_TWICE = 'refused twice';

    public const string OWNER_GONE = 'owner gone';

    public const string ADMIN_GONE = 'admin gone';

    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    /** How an end's reason reads in the audit log (A1); stored as its code. */
    public static function label(string $reason): string
    {
        return match ($reason) {
            self::BACK => __('Back to admin'),
            self::EXPIRED => __('Time ran out'),
            self::SIGNED_OUT => __('Signed out'),
            self::REFUSED_TWICE => __('Sent to refused pages in a row'),
            self::OWNER_GONE => __('The owner\'s account went'),
            self::ADMIN_GONE => __('The admin\'s access went'),
            default => $reason,
        };
    }

    public function handle(Session $session, string $why): ?Impersonation
    {
        $impersonation = Impersonation::of($session);

        if (! $impersonation instanceof Impersonation) {
            return null;
        }

        $admin = Auth::guard('web')->getProvider()->retrieveById($impersonation->adminId);

        // Forgotten first: the sign-out below ends no impersonation twice (lost()).
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

        $this->record($impersonation, $why, $admin instanceof User ? $admin : User::query()->find($impersonation->adminId));

        return $impersonation;
    }

    /**
     * The owner's session ended under the admin (their password changed, so
     * AuthenticateSession signed this device out, CurrentDeviceLogout): no
     * way back, but the end is recorded all the same.
     */
    public function lost(Session $session): void
    {
        $impersonation = Impersonation::of($session);

        if ($impersonation instanceof Impersonation) {
            Impersonation::forget($session);
            $this->record($impersonation, self::OWNER_GONE, User::query()->find($impersonation->adminId));
        }
    }

    private function record(Impersonation $impersonation, string $why, ?User $admin): void
    {
        $business = $this->context->bypass(fn (): ?Business => Business::query()->find($impersonation->businessId));

        // Businesses are archived, never deleted: kept for a row that went another way.
        if ($business instanceof Business) {
            DB::transaction(fn (): AuditLog => $this->recordAudit->handle('impersonation.ended', $business, $why, [
                'admin_id' => $impersonation->adminId,
                'owner_id' => $impersonation->ownerId,
            ], actor: $admin));
        }
    }
}
