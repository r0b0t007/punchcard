<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\TapStatus;
use App\Models\CounterScan;
use App\Models\Tap;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Proof that the customer is at the counter, which a redemption needs
 * (stamp-flow skill): where, when, and the staff member who confirmed it, if
 * any. Only built from proof, never from request data: a verified tap
 * (tap()), or a staff member's scan of the member QR (scan(), CHW-30). A
 * presence a staff member confirmed is itself the proof the customer stands
 * at the counter, so it needs no redeem window (needsRedeemWindow()).
 */
final readonly class RedeemPresence
{
    private function __construct(
        public int $businessId,
        public int $locationId,
        public CarbonInterface $at,
        public ?int $staffId = null,
    ) {}

    /** Whether the customer must have opened a redeem window: when no staff member confirmed them at the counter. */
    public function needsRedeemWindow(): bool
    {
        return $this->staffId === null;
    }

    /**
     * A verified tap, before ApplyTap gives it an outcome: its MAC checked and
     * its counter spent by ReceiveTap, on the stamper's business and location,
     * at the tap's time. Nobody confirmed it at the counter.
     */
    public static function tap(Tap $tap): self
    {
        $verified = $tap->status === TapStatus::Pending && $tap->rejection === null
            && $tap->nfc_tag_id !== null && $tap->counter !== null;

        if (! $verified || $tap->business_id === null || $tap->location_id === null || $tap->created_at === null) {
            throw new LogicException('Only a verified tap waiting for its outcome proves presence.');
        }

        return new self($tap->business_id, $tap->location_id, $tap->created_at);
    }

    /**
     * A staff member's scan of the customer's member QR (CounterScan, made only from a checked code by
     * ScanMemberQr): on its business and location, now, confirmed by the staff member who scanned; null when it ran
     * out, read at the same moment the presence is for, so no check made a moment earlier can disagree.
     */
    public static function scan(CounterScan $scan): ?self
    {
        if (! $scan->exists) {
            throw new LogicException('Only a scan ScanMemberQr made proves presence.');
        }

        $at = now();

        return $scan->hasExpired($at) ? null : new self($scan->business_id, $scan->location_id, $at, $scan->staff_id);
    }
}
