<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Enums\StampRejection;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Database\Outermost;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The referrer's stamp (CHW-47): +1 on their card once the friend they
 * invited, their email verified, proves they came in (a tap, a scan, a
 * stamp by hand: never a bonus), credited where the referrer comes (their
 * latest visit's business, else the friend's: ReferralCredit): a
 * second account of the referrer's own needs a real inbox for each stamp,
 * where no device is fingerprinted (decided). A friend not verified yet is
 * paid for at their first visit after. Once per friend whatever
 * the business, by its key ("referral:{friend's enrollment}", held by the
 * database too: stamp_events_referral_once), so the friend's later visits
 * and a job run again give nothing more. Within the card's monthly cap for
 * the referrer (referral_monthly_cap, the calendar month in the business's
 * time); over it, nothing, and a later visit of the friend's in a month with
 * room gives it. Nothing while the card's referrals are off.
 *
 * A system stamp (AddStamps): a card it completes unlocks its reward as any
 * stamp does. Credited to the business's first open site, in its tenant, in
 * a transaction run again if a deadlock aborts it (Outermost), the
 * referrer's card locked for the cap; a friend already counted is seen
 * before any lock. An account deleted meanwhile is skipped, anything else
 * refused is logged by id; a failure is logged and thrown, for the queued
 * listener to try again (GiveReferralStamps).
 */
final readonly class GiveReferralStamp
{
    public function __construct(private AddStamps $addStamps, private TenantContext $context, private ReferralCredit $credit) {}

    public static function key(int $friendEnrollmentId): string
    {
        return 'referral:'.$friendEnrollmentId;
    }

    /** @return bool whether the referrer got their stamp now */
    public function handle(int $friendEnrollmentId, int $businessId): bool
    {
        if ($this->counted($friendEnrollmentId)) {
            return false;
        }

        $friend = $this->context->bypass(fn (): ?CardEnrollment => CardEnrollment::query()->with('card:id,referrals_enabled')->find($friendEnrollmentId));

        if (! $friend instanceof CardEnrollment || $friend->referred_by === null || ! $friend->card->referrals_enabled) {
            return false;
        }

        [$business, $site] = $this->credit->site((int) $friend->referred_by, $businessId);

        if (! $business instanceof Business || ! $site instanceof Location) {
            return false;
        }

        $previous = $this->context->snapshot();

        try {
            $this->context->set($business->organization, $business);

            return Outermost::transaction('A referral stamp', fn (): bool => $this->give($friend, $site));
        } catch (StampRejected $refused) {
            if ($refused->rejection !== StampRejection::AccountDeleted) {
                Log::warning('A referral stamp was refused.', ['enrollment_id' => $friend->id, 'reason' => $refused->rejection->value]);
            }

            return false;
        } catch (Throwable $failed) {
            Log::warning('A referral stamp failed.', ['enrollment_id' => $friend->id, 'error' => $failed::class]);

            throw $failed;
        } finally {
            $this->context->restore($previous);
        }
    }

    /** Whether the friend was counted already (any business): one query, before anything else; checked again under the lock. */
    private function counted(int $friendEnrollmentId): bool
    {
        return $this->context->bypass(fn (): bool => StampEvent::query()->where('source', StampSource::Referral)->where('idempotency_key', self::key($friendEnrollmentId))->exists());
    }

    private function give(CardEnrollment $friend, Location $site): bool
    {
        $key = self::key($friend->id);
        $referrer = $this->context->bypass(function () use ($friend, $site, $key): ?CardEnrollment {
            $referrer = CardEnrollment::query()->whereKey($friend->referred_by)->lockForUpdate()->first();

            if (! $referrer instanceof CardEnrollment) {
                return null;
            }

            // Read under the lock: an owner lowering the cap, or turning referrals off, is seen.
            $card = LoyaltyCard::query()->whereKey($referrer->card_id)->first(['id', 'referrals_enabled', 'referral_monthly_cap']);
            $given = StampEvent::query()->where('source', StampSource::Referral)->where('idempotency_key', $key)->exists();
            $verified = User::query()->whereKey($friend->user_id)->whereNotNull('email_verified_at')->exists();
            $presence = StampEvent::query()->where('enrollment_id', $friend->id)->whereIn('source', StampSource::presenceValues())->exists();
            $thisMonth = $this->credit->thisMonth($referrer->id, $site, CarbonImmutable::now());

            return $card instanceof LoyaltyCard && $card->referrals_enabled && ! $given && $verified && $presence && $thisMonth < $card->referral_monthly_cap ? $referrer : null;
        });

        return $referrer instanceof CardEnrollment
            && ! $this->addStamps->handle($referrer, StampRequest::system(StampSource::Referral, $site, $key, 1))->replayed;
    }
}
