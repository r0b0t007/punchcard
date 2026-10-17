<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Actions\Cards\EnrollCustomer;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Actions\Stamps\StampResult;
use App\Enums\StampRejection;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\CounterScan;
use App\Models\Location;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Stamps the customer a staff member scanned (CHW-30, S2): source qr, the
 * staff device's idempotency key, the card the customer holds here or the
 * one they are enrolled on now (EnrollCustomer, as a first tap does). One
 * stamp per scan, by the staff member who scanned while they still work
 * there (LockScan), while it runs.
 *
 * With the scan row locked, the key comes first: a scan that already
 * stamped with this key gives that stamp again (AddStamps' replay), even
 * after it ran out, so a retry over a slow network never stamps twice nor
 * fails; with another key it is used. A refused stamp (cooldown, cap, a card
 * not running, a closed site) leaves the scan unused and enrolls nobody. A
 * key the device already used for another stamp is refused, never taken for
 * this one.
 */
final readonly class StampScan
{
    public function __construct(
        private AddStamps $addStamps,
        private EnrollCustomer $enrollCustomer,
        private LockScan $lockScan,
        private TenantContext $context,
    ) {}

    /**
     * @throws ScanRefused
     * @throws StampRejected
     */
    public function handle(CounterScan $scan, User $staff, int $qty, string $idempotencyKey): StampResult
    {
        return DB::transaction(function () use ($scan, $staff, $qty, $idempotencyKey): StampResult {
            $locked = $this->lockScan->handle($scan, $staff);

            $request = StampRequest::qr($this->context->bypass(fn (): Location => Location::query()->findOrFail($locked->location_id)), $staff, $idempotencyKey, $qty);

            if ($locked->stamp_event_id !== null) {
                return $this->again($locked, $request, $idempotencyKey);
            }

            if ($locked->hasExpired()) {
                throw new ScanRefused(ScanRefusal::Expired);
            }

            $result = $this->context->bypass(function () use ($locked, $request): StampResult {
                $enrollment = $this->enrollCustomer->handle(Business::query()->findOrFail($locked->business_id), User::query()->findOrFail($locked->user_id))
                    ?? throw new StampRejected(StampRejection::NotHonoured);

                return $this->addStamps->handle($enrollment, $request);
            });

            if ($result->replayed) {
                throw new StampRejected(StampRejection::IdempotencyConflict);
            }

            $locked->forceFill(['stamp_event_id' => $result->event->id])->save();

            return $result;
        });
    }

    /** The stamp a used scan gave, for a retry with the same key; another key finds it used. */
    private function again(CounterScan $scan, StampRequest $request, string $idempotencyKey): StampResult
    {
        $earlier = $this->context->bypass(fn (): StampEvent => StampEvent::query()->findOrFail($scan->stamp_event_id));

        if ($earlier->idempotency_key !== $idempotencyKey) {
            throw new ScanRefused(ScanRefusal::Used);
        }

        return $this->context->bypass(fn (): StampResult => $this->addStamps->handle(
            CardEnrollment::query()->findOrFail($earlier->enrollment_id),
            $request,
        ));
    }
}
