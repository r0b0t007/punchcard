<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardBusiness;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\StampEvent;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;

/**
 * "Invite a friend" on a customer's card (CHW-47, C6, design 20a): their
 * link to a join page, with their code, and the referral stamps they had
 * this month against the card's limit. The join page is the business they
 * last came to, else the first operating one honouring the card, so a
 * friend lands where they go. None while the card's referrals are off or
 * the card is paused, or for a card without a code.
 *
 * Read in bypass(), from DescribeMyCard: a customer has no tenant.
 */
final readonly class DescribeReferral
{
    /** @return array{url: string, cap: int, thisMonth: int}|null */
    public function handle(CardEnrollment $enrollment, LoyaltyCard $card, CarbonImmutable $now): ?array
    {
        if (! $card->referrals_enabled || ! $card->active || $enrollment->referral_code === null) {
            return null;
        }

        $honoured = Business::query()->operating()->whereIn('id', CardBusiness::query()->where('card_id', $card->id)->select('business_id'));
        $lastCame = StampEvent::query()->where('enrollment_id', $enrollment->id)->whereIn('source', StampSource::presenceValues())
            ->whereIn('business_id', (clone $honoured)->select('id'))
            ->latest('created_at')->latest('id')->value('business_id');
        $business = $lastCame === null ? $honoured->orderBy('id')->first(['id', 'slug']) : Business::query()->whereKey($lastCame)->first(['id', 'slug']);

        if (! $business instanceof Business) {
            return null;
        }

        $monthStart = $now->setTimezone(LocalMoment::timezoneOfBusiness($business->id))->startOfMonth()->utc();

        return [
            'url' => route('join.show', $business->slug).'?ref='.$enrollment->referral_code,
            'cap' => $card->referral_monthly_cap,
            'thisMonth' => StampEvent::query()->where('enrollment_id', $enrollment->id)->where('source', StampSource::Referral)
                ->where('created_at', '>=', $monthStart)->count(),
        ];
    }
}
