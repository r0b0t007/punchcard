<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Enums\TapStatus;
use App\Models\Tap;
use App\Models\User;
use App\Support\Taps\TapSession;
use App\Support\Tenancy\TenantContext;

/**
 * Applies a session's pending taps once its customer has signed in (CHW-25):
 * oldest first, each at its own time (a second one is usually refused for the
 * cooldown), each forgotten only once applied, so an error keeps the rest for
 * the next try. A tap received or claimed by another customer is skipped and
 * forgotten. Returns the tap to show, the one that stamped if any, and makes
 * it the session's result; null when there was nothing to claim.
 */
final readonly class ClaimPendingTaps
{
    public function __construct(private ApplyTap $applyTap, private TenantContext $context) {}

    public function handle(TapSession $session, User $user): ?Tap
    {
        $shown = null;

        foreach ($session->pending() as $id) {
            $tap = $this->context->bypass(fn (): ?Tap => Tap::query()->find($id));

            try {
                $tap = $tap instanceof Tap ? $this->applyTap->handle($tap, $user) : null;
            } catch (TapBelongsToAnotherCustomer) {
                $tap = null;
            }

            $session->forgetPending($id);

            if ($tap instanceof Tap && (! $shown instanceof Tap || $tap->status === TapStatus::Stamped)) {
                $shown = $tap;
            }
        }

        if ($shown instanceof Tap) {
            $session->show($shown);
        }

        return $shown;
    }
}
