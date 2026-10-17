<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Actions\Cards\ChooseCard;
use App\Enums\RewardStatus;
use App\Models\CardEnrollment;
use App\Models\CounterScan;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * What the scan result (S2, CHW-30) shows staff: the customer's first name
 * (so a code passed on from another phone shows), the card the stamp lands
 * on (ChooseCard, as StampScan's EnrollCustomer picks it: "new" when they
 * hold none here), their rewards ready on cards this business honours,
 * whether the scan already stamped or redeemed, and its seconds left.
 */
final readonly class DescribeScan
{
    public function __construct(private ChooseCard $chooseCard, private TenantContext $context) {}

    /**
     * @return array{firstName: string|null, card: array{name: string, stamps: int, required: int, reward: string, new: bool}|null, rewards: list<array{id: int, text: string}>, stamped: bool, redeemed: bool, secondsLeft: int}
     */
    public function handle(CounterScan $scan): array
    {
        $here = $this->chooseCard->handle($scan->business_id, $scan->user_id);

        return $this->context->bypass(fn (): array => [
            'firstName' => User::query()->findOrFail($scan->user_id)->firstName(),
            'card' => $here->card instanceof LoyaltyCard ? [
                'name' => $here->card->name,
                'stamps' => $here->enrollment instanceof CardEnrollment ? $here->enrollment->current_stamps : 0,
                'required' => $here->card->stamps_required,
                'reward' => $here->card->reward_text,
                'new' => ! $here->enrollment instanceof CardEnrollment,
            ] : null,
            'rewards' => array_values(Reward::query()
                ->where('status', RewardStatus::Available)
                ->whereIn('enrollment_id', $here->held->map(fn (CardEnrollment $held): int => $held->id)->values()->all())
                ->oldest('unlocked_at')
                ->get(['id', 'reward_text'])
                ->map(fn (Reward $reward): array => ['id' => $reward->id, 'text' => $reward->reward_text])
                ->all()),
            'stamped' => $scan->stamp_event_id !== null,
            'redeemed' => $scan->reward_id !== null,
            'secondsLeft' => max(0, $scan->expires_at->getTimestamp() - now()->getTimestamp()),
        ]);
    }
}
