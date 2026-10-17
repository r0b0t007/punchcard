<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Enums\RewardStatus;
use App\Models\CardEnrollment;
use App\Models\CounterScan;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\User;
use App\Support\Cards\CardChoice;
use App\Support\Tenancy\TenantContext;

/**
 * What the scan result (S2, CHW-30) shows staff: the customer's first name
 * (so a code passed on from another phone shows), the card they collect on
 * here, picked as their next stamp would be (CardChoice, nobody enrolled
 * yet: "new" when they hold none here), their rewards ready on cards this
 * business honours, whether the scan already stamped, and its seconds left.
 */
final readonly class DescribeScan
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{firstName: string|null, card: array{name: string, stamps: int, required: int, reward: string, new: bool}|null, rewards: list<array{id: int, text: string}>, used: bool, secondsLeft: int}
     */
    public function handle(CounterScan $scan): array
    {
        return $this->context->bypass(function () use ($scan): array {
            $customer = User::query()->findOrFail($scan->user_id);
            $honoured = LoyaltyCard::query()->honouredBy($scan->business_id)->get(['id', 'active', 'name', 'stamps_required', 'reward_text']);
            $held = CardEnrollment::query()->whereIn('card_id', $honoured->modelKeys())->where('user_id', $customer->id)->lastUsedFirst()->get()->keyBy('card_id');
            $cardId = CardChoice::pick($honoured->modelKeys(), $held->keys()->all(), $honoured->where('active', true)->modelKeys());
            $card = $cardId === null ? null : $honoured->firstWhere('id', $cardId);
            $enrollment = $cardId === null ? null : $held->get($cardId);

            return [
                'firstName' => $customer->firstName(),
                'card' => $card instanceof LoyaltyCard ? [
                    'name' => $card->name,
                    'stamps' => $enrollment instanceof CardEnrollment ? $enrollment->current_stamps : 0,
                    'required' => $card->stamps_required,
                    'reward' => $card->reward_text,
                    'new' => ! $enrollment instanceof CardEnrollment,
                ] : null,
                'rewards' => array_values(Reward::query()
                    ->where('status', RewardStatus::Available)
                    ->whereIn('enrollment_id', $held->map(fn (CardEnrollment $held): int => $held->id)->values()->all())
                    ->oldest('unlocked_at')
                    ->get(['id', 'reward_text'])
                    ->map(fn (Reward $reward): array => ['id' => $reward->id, 'text' => $reward->reward_text])
                    ->all()),
                'used' => $scan->stamp_event_id !== null,
                'secondsLeft' => max(0, $scan->expires_at->getTimestamp() - now()->getTimestamp()),
            ];
        });
    }
}
