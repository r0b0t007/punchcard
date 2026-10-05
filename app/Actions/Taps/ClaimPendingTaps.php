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
 * forgotten. The tap to show, the newest that stamped or else the newest
 * applied, becomes the session's result as soon as it is applied (a later
 * error keeps it); a stamp the session already shows this customer stays
 * shown over a refusal, so retrying after an error does not hide it. Returns
 * the tap to show, or null when there was nothing to claim.
 */
final readonly class ClaimPendingTaps
{
    public function __construct(private ApplyTap $applyTap, private TenantContext $context) {}

    public function handle(TapSession $session, User $user): ?Tap
    {
        $claimed = false;
        $shown = $session->shown();

        if (! $shown instanceof Tap || $shown->status !== TapStatus::Stamped || $shown->user_id !== $user->id) {
            $shown = null;
        }

        foreach ($session->pending() as $id) {
            $tap = $this->context->bypass(fn (): ?Tap => Tap::query()->find($id));

            try {
                $tap = $tap instanceof Tap ? $this->applyTap->handle($tap, $user) : null;
            } catch (TapBelongsToAnotherCustomer) {
                $tap = null;
            }

            $session->forgetPending($id);
            $claimed = $claimed || $tap instanceof Tap;

            if ($tap instanceof Tap && (! $shown instanceof Tap || $shown->status !== TapStatus::Stamped || $tap->status === TapStatus::Stamped)) {
                $shown = $tap;
                $session->show($shown);
            }
        }

        return $claimed ? $shown : null;
    }
}
