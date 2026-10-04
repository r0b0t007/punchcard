<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Actions\Cards\EnrollCustomer;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Stamper;
use App\Models\Tap;
use App\Models\User;
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
 *   customer is theirs alone;
 * - the customer is enrolled on the business's card (EnrollCustomer) and
 *   stamped through AddStamps, which locks the stamper, then the enrollment;
 * - an AddStamps refusal is recorded on the tap (TapRejection::fromStamp, the
 *   cooldown's next time too), never thrown: ReceiveTap already spent the
 *   counter, so the URL stays used whatever happens here.
 *
 * It runs in the stamper's tenant, without rights, so the queued listeners of
 * EnrollmentChanged know the organization and business (QueuedTenant); the
 * request's own tenant is put back afterwards.
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
            throw new LogicException('This tap was received by another customer.');
        }

        if (! $tap->isPending()) {
            return $tap;
        }

        if ($tap->expires_at === null || $tap->expires_at->isPast()) {
            return $this->finish($tap, ['status' => TapStatus::Expired, 'rejection' => TapRejection::Expired]);
        }

        [$stamper, $business] = $this->context->bypass(fn (): array => [
            Stamper::query()->findOrFail($tap->stamper_id),
            Business::query()->with('organization')->findOrFail($tap->business_id),
        ]);

        $this->context->set($business->organization, $business);
        $enrollment = $this->enrollCustomer->handle($business, $user);

        if (! $enrollment instanceof CardEnrollment) {
            return $this->finish($tap, ['user_id' => $user->id, 'status' => TapStatus::Rejected, 'rejection' => TapRejection::NotHonoured]);
        }

        try {
            $result = $this->addStamps->handle($enrollment, StampRequest::nfc($stamper, (int) $tap->counter, $tap->qty));
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
