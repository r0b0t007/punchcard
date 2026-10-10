<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\BusinessCategory;
use App\Models\Business;
use App\Models\LoyaltyCard;

/**
 * What the wizard's card step shows (CHW-31): the card the business
 * honours, or the suggestion for a first one: ten stamps and its
 * category's reward. Read in the business's tenant.
 */
final readonly class DescribeCardStep
{
    /**
     * @return array{card: ?LoyaltyCard, rewardText: string, stampsRequired: int}
     */
    public function handle(Business $business): array
    {
        $card = LoyaltyCard::query()->honouredBy($business->id)->first();

        return [
            'card' => $card,
            'rewardText' => $card->reward_text ?? ($business->category ?? BusinessCategory::Other)->defaultReward(),
            'stampsRequired' => $card->stamps_required ?? 10,
        ];
    }
}
