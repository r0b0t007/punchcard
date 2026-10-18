<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Fraud\AlertCapStreak;
use App\Actions\Fraud\AlertDisabledStamperTap;
use App\Events\DailyCapReached;
use App\Events\StamperDisabledTapped;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * The fraud alerts to a business's owners (CHW-36), queued: the tap and the
 * stamp never wait on mail. A send that fails is tried again, for the
 * owners it did not reach (MailsOwnersOnce).
 */
#[Backoff([60, 300])]
#[Tries(3)]
final readonly class SendFraudAlerts implements ShouldQueue
{
    public function __construct(
        private AlertDisabledStamperTap $alertDisabledStamperTap,
        private AlertCapStreak $alertCapStreak,
    ) {}

    public function handleStamperDisabledTapped(StamperDisabledTapped $event): void
    {
        $this->alertDisabledStamperTap->handle($event->stamperId, $event->tapId);
    }

    public function handleDailyCapReached(DailyCapReached $event): void
    {
        $this->alertCapStreak->handle($event->enrollmentId, $event->businessId, $event->date);
    }
}
