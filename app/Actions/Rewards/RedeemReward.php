<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\RedeemRefusal;
use App\Enums\RewardStatus;
use App\Events\EnrollmentChanged;
use App\Models\CardBusiness;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a reward (CHW-26, stamp-flow skill) with proof that the customer is
 * at the counter (RedeemPresence): their verified tap inside the redeem window
 * they opened (OpenRedeemWindow), at a business that honours the card (any
 * business of the franchise that does), at an open site. Needs a verified
 * email: an unverified account collects stamps but never cashes out.
 *
 * With the reward row locked (after the tap and the stamper, in ApplyTap): a
 * reward already redeemed returns its first redemption, with redeemedNow
 * false, whatever the new presence; never a second redemption. It records
 * where and when, for the franchise's "redeemed here" report (ADR 0006), and
 * redeemed_by only for a staff member who confirmed it (a tap has none). In
 * bypass(): the redeem Action is the one place a reward's outcome changes
 * (Reward::assertTenantWrite); the database keeps it final.
 */
final readonly class RedeemReward
{
    public function __construct(private TenantContext $context) {}

    public function handle(Reward $reward, User $user, RedeemPresence $presence): RedeemResult
    {
        [$result, $enrollment] = DB::transaction(fn (): array => $this->context->bypass(function () use ($reward, $user, $presence): array {
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();
            $enrollment = CardEnrollment::query()->findOrFail($locked->enrollment_id);

            if ($enrollment->user_id !== $user->id) {
                throw new RedeemRefused(RedeemRefusal::NotYours);
            }

            if ($locked->status === RewardStatus::Redeemed) {
                return [new RedeemResult($locked, redeemedNow: false), $enrollment];
            }

            $this->assertRedeemable($locked, $enrollment, $user, $presence);

            $locked->forceFill([
                'status' => RewardStatus::Redeemed,
                'redeemed_at' => $presence->at,
                'redeemed_by' => $presence->staffId,
                'redeemed_business_id' => $presence->businessId,
                'redeemed_location_id' => $presence->locationId,
                'redeem_window_opened_at' => null,
                'redeem_window_until' => null,
            ])->save();

            return [new RedeemResult($locked, redeemedNow: true), $enrollment];
        }));

        if ($result->redeemedNow) {
            EnrollmentChanged::dispatch($enrollment->id, (int) $enrollment->organization_id, $presence->businessId, [], [$result->reward->id]);
        }

        return $result;
    }

    private function assertRedeemable(Reward $reward, CardEnrollment $enrollment, User $user, RedeemPresence $presence): void
    {
        $opened = $reward->redeem_window_opened_at;
        $until = $reward->redeem_window_until;
        $refusal = match (true) {
            $reward->status !== RewardStatus::Available => RedeemRefusal::Unavailable,
            ! $user->hasVerifiedEmail() => RedeemRefusal::Unverified,
            $opened === null || $until === null || $presence->at->lt($opened) || $presence->at->gt($until) => RedeemRefusal::OutsideWindow,
            ! CardBusiness::query()->where('card_id', $enrollment->card_id)->where('business_id', $presence->businessId)->exists() => RedeemRefusal::NotHonoured,
            ! ArchivedSites::isOpen($presence->businessId, $presence->locationId) => RedeemRefusal::SiteClosed,
            default => null,
        };

        if ($refusal !== null) {
            throw new RedeemRefused($refusal);
        }
    }
}
