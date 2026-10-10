<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Cards\CreateFirstCard;
use App\Enums\OnboardingStep;
use App\Models\Business;
use App\Models\LoyaltyCard;
use Illuminate\Support\Facades\DB;

/** The wizard's card step (CHW-31): the business's first loyalty card (CreateFirstCard). */
final readonly class SaveCardStep
{
    public function __construct(
        private CreateFirstCard $createFirstCard,
        private CompleteStep $completeStep,
    ) {}

    public function handle(Business $business, string $rewardText, int $stampsRequired): LoyaltyCard
    {
        return DB::transaction(function () use ($business, $rewardText, $stampsRequired): LoyaltyCard {
            $card = $this->createFirstCard->handle($business, $rewardText, $stampsRequired);
            $this->completeStep->handle($business, OnboardingStep::Card);

            return $card;
        });
    }
}
