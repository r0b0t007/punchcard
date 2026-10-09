<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\RewardStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;

/**
 * My rewards (a first cut of C9, CHW-26): the customer's rewards still to
 * redeem, newest first, with the programme they come from and the day they
 * were unlocked, where they were. In bypass(): the
 * customer has no tenant; Reward::ownedBy keeps it to their own cards.
 */
final readonly class ListCustomerRewards
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return list<array{id: int, rewardText: string, businessName: string, brandColor: string|null, unlockedOn: string}>
     */
    public function handle(User $user): array
    {
        return $this->context->bypass(function () use ($user): array {
            $rewards = Reward::query()
                ->ownedBy($user)
                ->where('status', RewardStatus::Available)
                ->latest('unlocked_at')
                ->latest('id')
                ->get(['id', 'organization_id', 'reward_text', 'unlocked_at', 'stamp_event_id']);
            $organizations = Organization::query()
                ->whereKey($rewards->pluck('organization_id')->unique()->all())
                ->get(['id', 'name', 'brand_color'])
                ->keyBy('id');
            // The day it was unlocked where it was: the location of the stamp that unlocked it.
            $locationOf = StampEvent::query()
                ->whereKey($rewards->pluck('stamp_event_id')->filter()->all())
                ->pluck('location_id', 'id');
            $locations = Location::query()->whereKey($locationOf->unique()->all())->get(['id', 'timezone'])->keyBy('id');

            return array_values($rewards->map(fn (Reward $reward): array => [
                'id' => $reward->id,
                'rewardText' => $reward->reward_text,
                'businessName' => $organizations->get($reward->organization_id)->name ?? '',
                'brandColor' => $organizations->get($reward->organization_id)?->brand_color,
                'unlockedOn' => $reward->unlocked_at->copy()->setTimezone(LocalMoment::timezoneOf($locations->get($locationOf->get($reward->stamp_event_id))))->format('Y-m-d'),
            ])->all());
        });
    }
}
