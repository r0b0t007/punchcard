<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Enums\StamperStatus;
use App\Models\Stamper;
use App\Models\User;
use App\Support\Tenancy\ArchivedSites;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Next tap gives" (CHW-29, S1): the next verified tap on the stamper within
 * SECONDS gives that many stamps, once (ReceiveTap consumes it, within the
 * day's cap). Arming again replaces it, for the full time. Only a current,
 * enabled stamper at a site still open (a suspended business stamps
 * nothing). The stamper is locked as the tap locks it, then the site read
 * unlocked, as ReceiveTap does, so arming and a tap never cross. Who armed
 * it goes with it, to the tap and the stamp it gives (stamp_events.staff_id):
 * an armed tap gives stamps on their word. Whether it was armed. The caller
 * has checked StamperPolicy::arm.
 */
final readonly class ArmStamper
{
    /** How long an arming waits for its tap. */
    public const int SECONDS = 60;

    /** What counter mode offers ("Next tap gives"). The stamper allows up to 10 (StampRequest::MAX_TAP). */
    public const array QUANTITIES = [1, 2, 3, 5];

    public function handle(Stamper $stamper, int $qty, User $armedBy): bool
    {
        return DB::transaction(function () use ($stamper, $qty, $armedBy): bool {
            $locked = Stamper::query()->current()->whereKey($stamper->id)->lockForUpdate()->first();

            // Checked again on the locked row: a stamper moved meanwhile may be beyond a staff member's location.
            if (! $locked instanceof Stamper
                || Gate::forUser($armedBy)->denies('arm', $locked)
                || $locked->status !== StamperStatus::Active
                || ! ArchivedSites::isOpen($locked->business_id, $locked->location_id, counter: true)) {
                return false;
            }

            $locked->forceFill(['armed_qty' => $qty, 'armed_until' => now()->addSeconds(self::SECONDS), 'armed_by' => $armedBy->id])->save();

            return true;
        });
    }
}
