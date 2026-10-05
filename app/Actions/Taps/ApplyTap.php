<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Actions\Cards\EnrollCustomer;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Actions\Stamps\StampResult;
use App\Enums\StampRejection;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Stamper;
use App\Models\Tap;
use App\Models\User;
use App\Support\Database\Outermost;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Turns a pending tap into a stamp once its customer is known: at once when
 * they are signed in, after sign-in otherwise (CHW-25). With the tap row
 * locked, so a tap gives its stamp once:
 *
 * - a tap already applied, refused or expired is returned as it is; one
 *   waiting past its expiry becomes expired; one received by a signed-in
 *   customer, or already claimed, is theirs alone;
 * - the stamper is locked next, as ReceiveTap does (tag, stamper, then the
 *   enrollment in AddStamps): an archive ends stampers before closing
 *   anything (CloseSites), so a stamper still current under the lock keeps
 *   its site open until this commits. An ended stamper, one moved since the
 *   tap (the stamp is given where the tap happened), or a closed site refuses
 *   the tap before anyone is enrolled;
 * - the customer is enrolled on the business's card (EnrollCustomer) and
 *   stamped through AddStamps at the tap's own time, so the cooldown and the
 *   daily cap judge the visit, not the later sign-in; both in a savepoint, so
 *   a refused tap leaves no new card behind;
 * - an AddStamps refusal is recorded on the tap (TapRejection::fromStamp, the
 *   cooldown's next time too), never thrown: ReceiveTap already spent the
 *   counter, so the URL stays used whatever happens here.
 *
 * It runs in the stamper's tenant, without rights, so the queued listeners of
 * EnrollmentChanged know the organization and business (QueuedTenant); the
 * request's own tenant is put back afterwards. It refuses to run inside a
 * caller's transaction (Outermost): the listeners are dispatched when this one
 * commits, which must happen while the stamper's tenant is still set.
 */
final readonly class ApplyTap
{
    public function __construct(
        private TenantContext $context,
        private EnrollCustomer $enrollCustomer,
        private AddStamps $addStamps,
    ) {}

    public function handle(Tap $tap, User $user): Tap
    {
        Outermost::assert('ApplyTap');

        $previous = $this->context->snapshot();

        try {
            return DB::transaction(fn (): Tap => $this->apply($tap->id, $user));
        } finally {
            $this->context->restore($previous);
        }
    }

    private function apply(int $tapId, User $user): Tap
    {
        $tap = $this->context->bypass(fn (): Tap => Tap::query()->whereKey($tapId)->lockForUpdate()->firstOrFail());

        if ($tap->user_id !== null && $tap->user_id !== $user->id) {
            throw new TapBelongsToAnotherCustomer;
        }

        if (! $tap->isPending()) {
            return $tap;
        }

        if ($tap->expires_at === null || $tap->expires_at->isPast()) {
            return $this->finish($tap, ['user_id' => $user->id, 'status' => TapStatus::Expired, 'rejection' => TapRejection::Expired]);
        }

        [$stamper, $business] = $this->context->bypass(fn (): array => [
            Stamper::query()->whereKey($tap->stamper_id)->lockForUpdate()->firstOrFail(),
            Business::query()->with('organization')->findOrFail($tap->business_id),
        ]);

        // AddStamps checks a paused stamper and a closed site too; these come first because
        // enrolling a customer in a closed organization would throw, and an ended or moved
        // stamper has a clearer reason than "unavailable".
        $refusal = match (true) {
            $stamper->unassigned_at !== null || $stamper->location_id !== $tap->location_id => TapRejection::UnassignedTag,
            ! ArchivedSites::isOpen($stamper->business_id, $stamper->location_id) => TapRejection::SiteClosed,
            default => null,
        };

        if ($refusal !== null) {
            return $this->finish($tap, ['user_id' => $user->id, 'status' => TapStatus::Rejected, 'rejection' => $refusal]);
        }

        $this->context->set($business->organization, $business);

        // The tap keeps the card it is judged on and that card's stamps, refused or not, for its result page.
        try {
            $result = DB::transaction(function () use ($tap, $stamper, $business, $user): StampResult {
                $enrollment = $this->enrollCustomer->handle($business, $user)
                    ?? throw new StampRejected(StampRejection::NotHonoured);

                try {
                    return $this->addStamps->handle($enrollment, StampRequest::nfc($stamper, (int) $tap->counter, $tap->qty, $tap->created_at));
                } catch (StampRejected $rejected) {
                    // Nothing was written: the count read now (locked again, the refusal's savepoint
                    // released AddStamps' lock) is the card as the refusal found it.
                    $stamps = $this->context->bypass(fn (): mixed => CardEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->value('current_stamps'));

                    throw new StampRefusedOnCard($rejected, $enrollment->card_id, is_int($stamps) ? $stamps : 0);
                }
            });
        } catch (StampRejected|StampRefusedOnCard $refused) {
            $rejected = $refused instanceof StampRefusedOnCard ? $refused->rejected : $refused;

            return $this->finish($tap, [
                'user_id' => $user->id,
                'status' => TapStatus::Rejected,
                'rejection' => TapRejection::fromStamp($rejected->rejection),
                'available_at' => $rejected->availableAt,
                'card_id' => $refused instanceof StampRefusedOnCard ? $refused->cardId : null,
                'card_stamps' => $refused instanceof StampRefusedOnCard ? $refused->cardStamps : null,
            ]);
        }

        // The stamps given: an armed tap may get fewer, the room left under the daily cap.
        return $this->finish($tap, [
            'user_id' => $user->id,
            'status' => TapStatus::Stamped,
            'stamp_event_id' => $result->event->id,
            'qty' => $result->event->qty,
            'card_id' => $result->enrollment->card_id,
            'card_stamps' => $result->enrollment->current_stamps,
        ]);
    }

    /** @param  array<string, mixed>  $outcome */
    private function finish(Tap $tap, array $outcome): Tap
    {
        return $this->context->bypass(function () use ($tap, $outcome): Tap {
            $tap->forceFill($outcome)->save();

            return $tap;
        });
    }
}
