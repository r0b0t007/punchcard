<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Rewards\PushScanRedemption;
use App\Events\EnrollmentChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Tries;

/**
 * A redemption tells the customer (CHW-228) when staff made it with a scan of
 * their QR (PushScanRedemption), queued so the redeem never waits on a push
 * service. Only then: a tap's redemption queues nothing, so the tap stays
 * fast. One try: a retry could push to a phone twice; one that fails leaves
 * My cards' notice to tell them.
 */
#[Tries(1)]
final readonly class SendRedeemNotices implements ShouldQueue
{
    public function __construct(private PushScanRedemption $pushScanRedemption) {}

    public function handle(EnrollmentChanged $event): void
    {
        $this->pushScanRedemption->handle($event->enrollmentId, $event->organizationId, $event->businessId, $event->redeemedRewardIds);
    }

    public function shouldQueue(EnrollmentChanged $event): bool
    {
        return $event->redeemedRewardIds !== [] && $event->byScan;
    }
}
