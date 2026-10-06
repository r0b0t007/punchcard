<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Tap;
use App\Models\User;
use App\Support\Taps\TapSession;
use Throwable;

/**
 * Applies a session's pending taps for its signed-in customer (CHW-25),
 * oldest first, each at its own time (a second one is usually refused for
 * the cooldown), each forgotten once applied. A tap received or claimed by
 * another customer is skipped and forgotten. A tap that fails to apply is
 * reported and stays pending for the next try, without holding back the
 * newer ones.
 *
 * Then sets the session's result once: a stamp or a redemption (the newest
 * applied, or one already shown to this customer, so a retry does not hide
 * it), else the tap
 * just received ($received, when it was not pending, unless it only replays a
 * URL: then the pending tap it retried tells the real outcome), else the newest tap
 * applied. Returns it, or null when there was nothing to claim or show.
 */
final readonly class ClaimPendingTaps
{
    public function __construct(private ApplyTap $applyTap) {}

    public function handle(TapSession $session, User $user, ?Tap $received = null): ?Tap
    {
        $shown = $session->shown();
        $stamped = $shown instanceof Tap && $this->gave($shown) && $shown->user_id === $user->id ? $shown : null;
        $newest = null;

        foreach ($session->pendingTaps() as $waiting) {
            try {
                $tap = $this->applyTap->handle($waiting, $user);
            } catch (TapBelongsToAnotherCustomer) {
                $session->forgetPending($waiting->id);
                $tap = null;
            } catch (Throwable $failed) {
                report($failed);

                continue;
            }

            // ApplyTap clears the link with an outcome; a tap returned as it was (expired) is let go here.
            if ($tap?->claim_token_hash !== null) {
                $session->forgetPending($waiting->id);
            }

            if ($tap instanceof Tap) {
                $newest = $tap;
                $stamped = $this->gave($tap) ? $tap : $stamped;
            }
        }

        $replayed = $received instanceof Tap && $received->rejection === TapRejection::Replay;
        $result = $newest instanceof Tap ? ($stamped ?? ($replayed ? $newest : $received) ?? $newest) : $received;

        if ($result instanceof Tap) {
            $session->show($result);
        }

        return $result;
    }

    /** The tap gave something: a stamp, or a redeemed reward (CHW-26). */
    private function gave(Tap $tap): bool
    {
        return in_array($tap->status, [TapStatus::Stamped, TapStatus::Redeemed], true);
    }
}
