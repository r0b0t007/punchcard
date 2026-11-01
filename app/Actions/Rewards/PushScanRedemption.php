<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\RewardStatus;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\User;
use App\Notifications\RewardRedeemed;
use App\Support\Push\PushKey;
use App\Support\Tenancy\TenantContext;

/**
 * Tells the customer at once that staff redeemed their reward with a scan of
 * their QR (CHW-228, design 14a), from the queued listener on the redemption
 * (SendRedeemNotices): a safeguard, as the scan, not the customer, opened it.
 *
 * - Only rewards redeemed by a scan (redeemed_scan_id): never one the customer
 *   redeemed with their own tap, which they saw happen. A replayed
 *   redemption dispatches nothing (RedeemReward).
 * - Not once they were told (redeem_notice_at), nor to an anonymised account.
 *   Whatever their "Rewards ready" switch (push_rewards): a safeguard, not
 *   news.
 * - On their phones with notifications on, titled with the redeeming place;
 *   a push service accepting it marks them told (MarkRedeemNoticeSent).
 *   Otherwise My cards tells them (DescribeRedeemNotices).
 *
 * The reads are in bypass(), every one kept to the enrollment and its
 * organization.
 */
final readonly class PushScanRedemption
{
    public function __construct(private TenantContext $context) {}

    /** @param  list<int>  $rewardIds  the rewards the redemption redeemed */
    public function handle(int $enrollmentId, int $organizationId, int $businessId, array $rewardIds): void
    {
        if ($rewardIds === [] || PushKey::public() === null) {
            return;
        }

        $this->context->bypass(function () use ($enrollmentId, $organizationId, $businessId, $rewardIds): void {
            $user = CardEnrollment::query()->with('user')->whereKey($enrollmentId)->where('organization_id', $organizationId)->first()?->user;

            if (! $user instanceof User || $user->isAnonymised() || ! $user->pushSubscriptions()->exists()) {
                return;
            }

            $rewards = Reward::query()->whereKey($rewardIds)
                ->where('enrollment_id', $enrollmentId)
                ->where('status', RewardStatus::Redeemed)
                ->whereNotNull('redeemed_scan_id')
                ->whereNull('redeem_notice_at')
                ->get();
            $place = (string) Business::query()->whereKey($businessId)->where('organization_id', $organizationId)->value('name');

            foreach ($rewards as $reward) {
                $user->notifyNow(new RewardRedeemed($reward->id, $enrollmentId, $place, $reward->reward_text));
            }
        });
    }
}
