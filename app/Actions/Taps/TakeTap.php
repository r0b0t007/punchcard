<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Enums\TapRejection;
use App\Models\Tap;
use App\Models\User;
use App\Support\Taps\TapSession;

/**
 * A tap URL opened in a browser session (CHW-25). ReceiveTap, then the tap
 * becomes the session's result and, while pending, one of its pending taps,
 * before anything else can fail: a stamp that fails to apply is retried
 * later instead of lost (an armed tap's stamps included).
 *
 * - Signed in: the session's pending taps are claimed (ClaimPendingTaps),
 *   also when this tap is not pending (reloading a URL whose stamp failed
 *   is a replay, but the failed tap still waits), and the result is the one
 *   ClaimPendingTaps picks.
 * - Signed out: the tap waits for sign-in. A replay (the Back button
 *   re-requests the URL) keeps showing a tap still waiting, so the customer
 *   still sees how to keep it.
 *
 * Returns the tap the result page shows.
 */
final readonly class TakeTap
{
    public function __construct(private ReceiveTap $receiveTap, private ClaimPendingTaps $claimPendingTaps) {}

    public function handle(string $e, string $c, ?User $user, ?string $ip, ?string $userAgent, TapSession $session): Tap
    {
        $tap = $this->receiveTap->handle($e, $c, $user, $ip, $userAgent);
        $session->show($tap);

        if ($tap->isPending()) {
            $session->keepPending($tap);
        }

        if ($user instanceof User) {
            return $this->claimPendingTaps->handle($session, $user, $tap->isPending() ? null : $tap) ?? $tap;
        }

        $waiting = $tap->rejection === TapRejection::Replay ? $session->newestWaiting() : null;

        if ($waiting instanceof Tap) {
            $session->show($waiting);

            return $waiting;
        }

        return $tap;
    }
}
