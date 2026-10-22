<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Actions\Account\RecordActivity;
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
 * they opened (OpenRedeemWindow), or a staff member's scan of their member QR
 * (CHW-30, no window: the scan is the proof), at a business that honours the card (any
 * business of the franchise that does), at an open site of a business not
 * suspended (ArchivedSites' counter, CHW-22). Needs a verified
 * email: an unverified account collects stamps but never cashes out. Never
 * on a deleted account (CHW-253): DeleteAccount expires its rewards, so a
 * redemption waiting on the reward's lock finds it gone.
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
    public function __construct(private TenantContext $context, private RecordActivity $recordActivity) {}

    public function handle(Reward $reward, User $user, RedeemPresence $presence): RedeemResult
    {
        $result = DB::transaction(fn (): RedeemResult => $this->context->bypass(function () use ($reward, $user, $presence): RedeemResult {
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();
            $enrollment = CardEnrollment::query()->findOrFail($locked->enrollment_id);

            if ($enrollment->user_id !== $user->id) {
                throw new RedeemRefused(RedeemRefusal::NotYours);
            }

            // Before the replay below, so a business never gets back a redemption, nor a reward, of a card it doesn't honour.
            if (! $this->honours($presence->businessId, $enrollment)) {
                throw new RedeemRefused(RedeemRefusal::NotHonoured);
            }

            if ($locked->status === RewardStatus::Redeemed) {
                return new RedeemResult($locked, redeemedNow: false);
            }

            $this->assertRedeemable($locked, $user, $presence);

            $locked->forceFill([
                'status' => RewardStatus::Redeemed,
                'redeemed_at' => $presence->at,
                'redeemed_by' => $presence->staffId,
                'redeemed_business_id' => $presence->businessId,
                'redeemed_location_id' => $presence->locationId,
                'redeemed_scan_id' => $presence->scanId,
                'redeem_window_opened_at' => null,
                'redeem_window_until' => null,
            ])->save();
            // The customer was there: their account is in use (CHW-58), noted once this commits.
            $this->recordActivity->later($user->id, $presence->at);

            return new RedeemResult($locked, redeemedNow: true);
        }));

        if ($result->redeemedNow) {
            EnrollmentChanged::dispatch($result->reward->enrollment_id, $result->reward->organization_id, $presence->businessId, [], [$result->reward->id]);
        }

        return $result;
    }

    /** Whether the business honours the card (any business of the franchise that does). */
    private function honours(int $businessId, CardEnrollment $enrollment): bool
    {
        return CardBusiness::query()->where('card_id', $enrollment->card_id)->where('business_id', $businessId)->exists();
    }

    private function assertRedeemable(Reward $reward, User $user, RedeemPresence $presence): void
    {
        $refusal = match (true) {
            User::isGone($user->id) => RedeemRefusal::AccountDeleted,
            $reward->status !== RewardStatus::Available => RedeemRefusal::Unavailable,
            ! $user->hasVerifiedEmail() => RedeemRefusal::Unverified,
            $presence->needsRedeemWindow() && ! $reward->isRedeemWindowOpenAt($presence->at) => RedeemRefusal::OutsideWindow,
            ! ArchivedSites::isOpen($presence->businessId, $presence->locationId, lock: $presence->staffId !== null, counter: true) => RedeemRefusal::SiteClosed,
            default => null,
        };

        if ($refusal !== null) {
            throw new RedeemRefused($refusal);
        }
    }
}
