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
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Database\Outermost;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The referrer's stamp (CHW-47): +1 on their card once the friend they
 * invited, their email verified, proves they came in (a tap, a scan, a
 * stamp by hand: never a bonus), at the business where the friend did: a
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
    public function __construct(private AddStamps $addStamps, private TenantContext $context) {}

    public static function key(int $friendEnrollmentId): string
    {
        return 'referral:'.$friendEnrollmentId;
    }

    /** @return bool whether the referrer got their stamp now */
    public function handle(int $friendEnrollmentId, int $businessId): bool
    {
        [$friend, $business, $site] = $this->context->bypass(fn (): array => [
            CardEnrollment::query()->with('card:id,organization_id,referrals_enabled,referral_monthly_cap')->find($friendEnrollmentId),
            Business::query()->with('organization')->find($businessId),
            Location::query()->where('business_id', $businessId)->open()->orderBy('id')->first(),
        ]);

        if (! $friend instanceof CardEnrollment || $friend->referred_by === null || ! $friend->card->referrals_enabled
            || ! $business instanceof Business || ! $site instanceof Location || $this->counted($friend)) {
            return false;
        }

        $previous = $this->context->snapshot();

        try {
            $this->context->set($business->organization, $business);

            return Outermost::transaction('A referral stamp', fn (): bool => $this->give($friend, $business, $site));
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

    /** Whether the friend was counted already (any business): seen without a lock, checked again under it. */
    private function counted(CardEnrollment $friend): bool
    {
        return $this->context->bypass(fn (): bool => StampEvent::query()->where('source', StampSource::Referral)->where('idempotency_key', self::key($friend->id))->exists());
    }

    private function give(CardEnrollment $friend, Business $business, Location $site): bool
    {
        $key = self::key($friend->id);
        $referrer = $this->context->bypass(function () use ($friend, $business, $key): ?CardEnrollment {
            $referrer = CardEnrollment::query()->whereKey($friend->referred_by)->lockForUpdate()->first();

            if (! $referrer instanceof CardEnrollment) {
                return null;
            }

            $given = StampEvent::query()->where('source', StampSource::Referral)->where('idempotency_key', $key)->exists();
            $verified = User::query()->whereKey($friend->user_id)->whereNotNull('email_verified_at')->exists();
            $presence = StampEvent::query()->where('enrollment_id', $friend->id)->whereIn('source', StampSource::presenceValues())->exists();
            $monthStart = CarbonImmutable::now(LocalMoment::timezoneOfBusiness($business->id))->startOfMonth()->utc();
            $thisMonth = StampEvent::query()->where('enrollment_id', $referrer->id)->where('source', StampSource::Referral)
                ->where('created_at', '>=', $monthStart)->count();

            return ! $given && $verified && $presence && $thisMonth < $friend->card->referral_monthly_cap ? $referrer : null;
        });

        return $referrer instanceof CardEnrollment
            && ! $this->addStamps->handle($referrer, StampRequest::system(StampSource::Referral, $site, $key, 1))->replayed;
    }
}
