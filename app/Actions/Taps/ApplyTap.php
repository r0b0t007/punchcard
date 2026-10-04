<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Actions\Cards\EnrollCustomer;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Actions\Stamps\StampResult;
use App\Enums\StamperStatus;
use App\Enums\StampRejection;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Business;
use App\Models\Stamper;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

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
 *   its site open until this commits. An ended or paused stamper, or a closed
 *   site, refuses the tap before anyone is enrolled;
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
 * request's own tenant is put back afterwards. Call it outside any
 * transaction: the listeners are dispatched when this one commits, which must
 * happen while the stamper's tenant is still set.
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
            throw new LogicException('This tap was received or claimed by another customer.');
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

        $refusal = match (true) {
            $stamper->unassigned_at !== null => TapRejection::UnassignedTag,
            $stamper->status !== StamperStatus::Active => TapRejection::StamperDisabled,
            ! ArchivedSites::isOpen($stamper->business_id, $stamper->location_id) => TapRejection::SiteClosed,
            default => null,
        };

        if ($refusal !== null) {
            return $this->finish($tap, ['user_id' => $user->id, 'status' => TapStatus::Rejected, 'rejection' => $refusal]);
        }

        $this->context->set($business->organization, $business);

        try {
            $result = DB::transaction(function () use ($tap, $stamper, $business, $user): StampResult {
                $enrollment = $this->enrollCustomer->handle($business, $user)
                    ?? throw new StampRejected(StampRejection::NotHonoured);

                return $this->addStamps->handle($enrollment, StampRequest::nfc($stamper, (int) $tap->counter, $tap->qty, $tap->created_at));
            });
        } catch (StampRejected $rejected) {
            return $this->finish($tap, [
                'user_id' => $user->id,
                'status' => TapStatus::Rejected,
                'rejection' => TapRejection::fromStamp($rejected->rejection),
                'available_at' => $rejected->availableAt,
            ]);
        }

        return $this->finish($tap, ['user_id' => $user->id, 'status' => TapStatus::Stamped, 'stamp_event_id' => $result->event->id]);
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
