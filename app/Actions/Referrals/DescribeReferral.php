<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use App\Models\Business;
use App\Models\CardBusiness;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use Carbon\CarbonImmutable;

/**
 * "Invite a friend" on a customer's card (CHW-47, C6, design 20a): their
 * link to a join page, with their code, and the referral stamps they had
 * this month against the card's limit. None while the card's referrals are
 * off or the card is paused, or for a card without a code.
 *
 * The join page is one where the friend can get this card and come in: an
 * operating business honouring it with an open site, whose card for someone
 * new is this one (CardChoice: its oldest running card), so the referral
 * counts (ReferrerFor). The one the customer last came to among those, else
 * the first. The month's count is the one the limit is checked against
 * (ReferralCredit), in the time of the site the next stamp would go to.
 *
 * Read in bypass(), from DescribeMyCard: a customer has no tenant.
 */
final readonly class DescribeReferral
{
    public function __construct(private ReferralCredit $credit) {}

    /** @return array{url: string, cap: int, thisMonth: int}|null */
    public function handle(CardEnrollment $enrollment, LoyaltyCard $card, CarbonImmutable $now): ?array
    {
        if (! $card->referrals_enabled || ! $card->active || $enrollment->referral_code === null) {
            return null;
        }

        $joinable = Business::query()->operating()
            ->whereIn('id', CardBusiness::query()->where('card_id', $card->id)->select('business_id'))
            ->whereIn('id', Location::query()->open()->select('business_id'))
            // A business running an older card gives that one to someone new.
            ->whereNotIn('id', CardBusiness::query()->whereIn('card_id', LoyaltyCard::query()->where('active', true)->where('id', '<', $card->id)->select('id'))->select('business_id'));
        $lastCame = $this->credit->lastCameTo($enrollment->id, $joinable);
        $business = $lastCame === null ? $joinable->orderBy('id')->first(['id', 'slug']) : Business::query()->whereKey($lastCame)->first(['id', 'slug']);

        if (! $business instanceof Business) {
            return null;
        }

        [, $site] = $this->credit->site($enrollment->id, $business->id);

        return [
            'url' => route('join.show', $business->slug).'?ref='.$enrollment->referral_code,
            'cap' => $card->referral_monthly_cap,
            'thisMonth' => $site instanceof Location ? $this->credit->thisMonth($enrollment->id, $site, $now) : 0,
        ];
    }
}
