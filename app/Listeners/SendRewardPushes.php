<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Push\PushRewardsReady;
use App\Events\EnrollmentChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * "Your reward is ready" pushed (CHW-41), queued: the stamp never waits on
 * a push service. Only a change that unlocked a reward away from the
 * customer's own tap is queued at all: the tap's response never pays for it.
 */
#[Backoff([60, 300])]
#[Tries(3)]
final readonly class SendRewardPushes implements ShouldQueue
{
    public function __construct(private PushRewardsReady $pushRewardsReady) {}

    public function handle(EnrollmentChanged $event): void
    {
        $this->pushRewardsReady->handle($event->enrollmentId, $event->organizationId, $event->businessId, $event->rewardIds);
    }

    public function shouldQueue(EnrollmentChanged $event): bool
    {
        return $event->rewardIds !== [] && ! $event->byOwnTap;
    }
}
