<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Reward;
use App\Models\User;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;

/**
 * The redeem screen (C4, CHW-26) for one of the customer's rewards: its
 * state, the window's length and the seconds left in an open one, whether the
 * customer may redeem (a verified email), and once redeemed where and when,
 * in the location's time.
 *
 * A redemption is "live" (the screen staff hand the reward over on) for
 * Reward::LIVE_SECONDS after it, and the screen counts that down itself: how
 * long is left, never a flag that stays on while a tab stays open. The window's
 * seconds left are rounded down, so the ring never outlasts the window.
 */
final readonly class DescribeCustomerReward
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{id: int, rewardText: string, businessName: string, brandColor: string|null, status: string, verified: bool, windowSeconds: int, secondsLeft: int, redeemed: array{at: array{day: 'today'|'other', date: string, time: string}, locationName: string|null, liveSeconds: int}|null}
     */
    public function handle(Reward $reward, User $user): array
    {
        return $this->context->bypass(function () use ($reward, $user): array {
            $organization = Organization::query()->find($reward->organization_id, ['id', 'name', 'brand_color']);
            $until = $reward->redeem_window_until;
            $redeemed = null;

            if ($reward->redeemed_at !== null) {
                $location = Location::query()->find($reward->redeemed_location_id, ['id', 'name', 'timezone']);
                $redeemed = [
                    'at' => LocalMoment::of($reward->redeemed_at, $location->timezone ?? (string) config('app.timezone')),
                    'locationName' => $location?->name,
                    'liveSeconds' => $reward->liveSecondsLeft(),
                ];
            }

            return [
                'id' => $reward->id,
                'rewardText' => $reward->reward_text,
                'businessName' => $organization->name ?? '',
                'brandColor' => $organization?->brand_color,
                'status' => $reward->status->value,
                'verified' => $user->hasVerifiedEmail(),
                'windowSeconds' => OpenRedeemWindow::SECONDS,
                'secondsLeft' => $until?->isFuture() === true ? (int) floor(now()->diffInSeconds($until)) : 0,
                'redeemed' => $redeemed,
            ];
        });
    }
}
