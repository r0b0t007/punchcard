<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Enums\CardMode;
use App\Enums\RewardType;
use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A business's first loyalty card (CHW-31, the onboarding wizard): a cyclic
 * card with a free item, in its organization's program, honoured by the
 * business. The full card builder is CHW-32. Done again, it updates that
 * card, until customers hold it: then their stamps count on it as it is, and
 * the card builder changes it (the same values again pass, and the card
 * keeps the business's name). Runs in the business's tenant, as its org
 * admin (an independent owner is): LoyaltyCard's guard decides.
 */
final readonly class CreateFirstCard
{
    public function __construct(private TenantContext $context) {}

    public function handle(Business $business, string $rewardText, int $stampsRequired): LoyaltyCard
    {
        return DB::transaction(function () use ($business, $rewardText, $stampsRequired): LoyaltyCard {
            // The business first, as CompleteStep locks it: a second request (another tab) waits
            // here, then finds the card this one made, instead of making a second.
            $this->context->bypass(fn (): Business => Business::query()->lock('for no key update')->findOrFail($business->id));

            $card = LoyaltyCard::query()->honouredBy($business->id)->lockForUpdate()->first();

            if (! $card instanceof LoyaltyCard) {
                $card = (new LoyaltyCard)->forceFill([
                    'organization_id' => $business->organization_id,
                    'name' => $business->name,
                    'mode' => CardMode::Cyclic,
                    'reward_type' => RewardType::Item,
                    'reward_text' => $rewardText,
                    'stamps_required' => $stampsRequired,
                ]);
                $card->save();
                $card->businesses()->attach($business->id);

                return $card;
            }

            $rulesChange = $card->reward_text !== $rewardText || $card->stamps_required !== $stampsRequired;

            if ($rulesChange && $this->context->bypass(fn (): bool => LoyaltyCard::query()->whereKey($card->id)->held()->exists())) {
                throw ValidationException::withMessages([
                    'reward_text' => __('Customers already hold this card: change it in the card builder.'),
                ]);
            }

            // The card carries the business's name, also after the business step is done again.
            $card->forceFill(['name' => $business->name, ...$rulesChange ? ['reward_text' => $rewardText, 'stamps_required' => $stampsRequired] : []])->save();

            return $card;
        });
    }
}
