<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\RewardStatus;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\Reward;
use App\Models\User;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;

/**
 * My cards' notices (CHW-228, design 14b): the customer's rewards staff
 * redeemed with a scan of their QR in the last DAYS days that nothing told
 * them about yet (no push accepted, no Got it), newest first, SHOWN at most.
 * Each with the reward, the place and when, in the redeeming site's time.
 *
 * In bypass(): the customer's rewards across organizations, kept to their own
 * enrollments.
 */
final readonly class DescribeRedeemNotices
{
    public const int DAYS = 30;

    public const int SHOWN = 3;

    public function __construct(private TenantContext $context) {}

    /** @return list<array{id: int, reward: string, business: string, at: array{day: 'today'|'other', date: string, time: string}}> */
    public function handle(User $user): array
    {
        return $this->context->bypass(function () use ($user): array {
            $rewards = Reward::query()
                ->whereIn('enrollment_id', CardEnrollment::query()->select('id')->where('user_id', $user->id))
                ->where('status', RewardStatus::Redeemed)
                ->whereNotNull('redeemed_scan_id')
                ->whereNull('redeem_notice_at')
                ->where('redeemed_at', '>', now()->subDays(self::DAYS))
                ->latest('redeemed_at')->latest('id')->limit(self::SHOWN)
                ->get();
            $businesses = Business::query()->whereKey($rewards->pluck('redeemed_business_id')->filter()->unique()->values()->all())->pluck('name', 'id');
            $locations = Location::query()->whereKey($rewards->pluck('redeemed_location_id')->filter()->unique()->values()->all())->get()->keyBy('id');

            return array_values($rewards->map(fn (Reward $reward): array => [
                'id' => $reward->id,
                'reward' => $reward->reward_text,
                'business' => (string) $businesses->get((int) $reward->redeemed_business_id, ''),
                'at' => LocalMoment::of($reward->redeemed_at ?? now(), LocalMoment::timezoneOf($locations->get((int) $reward->redeemed_location_id))),
            ])->all());
        });
    }
}
