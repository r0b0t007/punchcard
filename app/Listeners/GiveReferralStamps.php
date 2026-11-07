<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Referrals\GiveReferralStamp;
use App\Events\EnrollmentChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * A referrer's stamp (CHW-47), queued: the friend's tap never waits on it,
 * nor locks a second card. Only a referred friend's stamp that proves
 * presence is queued at all; whether it counts, and within the cap, is
 * GiveReferralStamp's to say, once per friend.
 */
#[Backoff([60, 300])]
#[Tries(3)]
final readonly class GiveReferralStamps implements ShouldQueue
{
    public function __construct(private GiveReferralStamp $giveReferralStamp) {}

    public function handle(EnrollmentChanged $event): void
    {
        $this->giveReferralStamp->handle($event->enrollmentId, $event->businessId);
    }

    public function shouldQueue(EnrollmentChanged $event): bool
    {
        return $event->presence && $event->referred;
    }
}
