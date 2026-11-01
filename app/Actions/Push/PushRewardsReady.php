<?php

declare(strict_types=1);

namespace App\Actions\Push;

use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Notifications\RewardReady;
use App\Support\Push\PushKey;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * "Your reward is ready" to the customer's phones (CHW-41, 11d), for the
 * rewards a change of their card unlocked (EnrollmentChanged).
 *
 * - Not one their own tap unlocked: its screen (C3) already says it, on
 *   the phone in their hand. A staff scan, a manual stamp or a bonus is
 *   pushed.
 * - Only a reward of that card, still available.
 * - Nobody who turned "Rewards ready" off, has no phone, or whose account
 *   is being deleted; nothing when the app has no VAPID key.
 * - Once per reward: push_sent_at is claimed before sending, still
 *   available, so a job run again never pushes it twice, and one redeemed
 *   meanwhile not at all (one lost on the way is not sent again).
 *
 * The job runs in the tenant that dispatched it (QueuedTenant: the stamp's
 * business, which fails it if that business closed meanwhile); the reads
 * are in bypass(), every one kept to the enrollment and its organization.
 */
final readonly class PushRewardsReady
{
    public function __construct(private TenantContext $context) {}

    /** @param  list<int>  $rewardIds */
    public function handle(int $enrollmentId, int $organizationId, int $businessId, array $rewardIds): void
    {
        if ($rewardIds === [] || PushKey::public() === null) {
            return;
        }

        $this->context->bypass(function () use ($enrollmentId, $organizationId, $businessId, $rewardIds): void {
            $user = CardEnrollment::query()->with('user')->whereKey($enrollmentId)->first()?->user;

            if (! $user instanceof User || $user->isAnonymised() || ! $user->push_rewards || ! $user->pushSubscriptions()->exists()) {
                return;
            }

            $rewards = Reward::query()->whereKey($rewardIds)->where('enrollment_id', $enrollmentId)->whereNull('push_sent_at')->orderBy('milestone')->get();
            $tapped = StampEvent::query()->whereKey($rewards->pluck('stamp_event_id')->filter()->all())
                ->where('source', StampSource::Nfc)->pluck('id')->all();
            $place = (string) Business::query()->whereKey($businessId)->where('organization_id', $organizationId)->value('name');

            foreach ($rewards as $reward) {
                if (in_array($reward->stamp_event_id, $tapped, true)) {
                    continue;
                }

                $claimed = Reward::query()->whereKey($reward->id)->where('status', RewardStatus::Available)->whereNull('push_sent_at')
                    ->update(['push_sent_at' => Carbon::now()]);

                if ($claimed === 1) {
                    $user->notify(new RewardReady($reward->id, $enrollmentId, $place, $reward->reward_text));
                }
            }
        });
    }
}
