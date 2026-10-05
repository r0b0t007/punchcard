<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Enums\TapStatus;
use App\Models\Tap;
use App\Models\User;
use App\Support\Taps\TapSession;

/**
 * A tap URL opened in a browser session (CHW-25): ReceiveTap, then the tap
 * becomes the session's result and, while pending, one of its pending taps
 * before anything else can fail, so a stamp that fails to apply is retried
 * from the result page instead of lost (an armed tap's stamps included). A
 * signed-in customer's pending taps are claimed at once (ClaimPendingTaps),
 * also when this tap is not pending (a reload of a URL whose stamp failed to
 * apply is a replay, but the failed tap still waits); a signed-out
 * customer's wait for sign-in. Returns the tap to show: one that stamped,
 * else this one.
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

        if (! $user instanceof User) {
            return $tap;
        }

        $claimed = $this->claimPendingTaps->handle($session, $user);

        if ($claimed instanceof Tap && ($tap->isPending() || $claimed->status === TapStatus::Stamped)) {
            return $claimed;
        }

        $session->show($tap);

        return $tap;
    }
}
