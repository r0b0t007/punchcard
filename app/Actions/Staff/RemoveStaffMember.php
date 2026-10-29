<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Actions\Stamps\ArmingCounts;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Takes a staff member off a business's staff (CHW-146): their membership
 * ends, their account stays (their stamps keep their name, and they may
 * belong to other businesses or collect stamps themselves). Staff only, in
 * the same statement that deletes, so an owner (or someone made one
 * meanwhile) is never removed here: owners are handed over (CHW-140). The
 * business is locked before the membership, the order every staff Action
 * locks in (inviting, joining, cancelling). Through the guarded pivot model,
 * in the owner's tenant. Their next request is no longer in this business
 * (ResolveTenant).
 *
 * Their armings there end with them (CHW-309), unless they still work
 * there as an org admin: the counter would show them for up to a minute,
 * and a re-invited member would bring them back. The business's current
 * stampers are locked before the business and again after it, as a
 * suspension locks them (one assigned meanwhile included). An arming under
 * way holds their membership share-locked (ArmStamper), so the delete waits
 * for it and the disarm after sees it, or it waits and finds them gone.
 * Whether they were removed.
 */
final readonly class RemoveStaffMember
{
    public function __construct(private TenantContext $context, private ArmingCounts $armingCounts) {}

    public function handle(BusinessMember $membership): bool
    {
        return DB::transaction(function () use ($membership): bool {
            $this->lockStampers($membership->business_id);
            Business::query()->whereKey($membership->business_id)->lockForUpdate()->first();
            $this->lockStampers($membership->business_id);

            if (BusinessMember::query()->whereKey($membership->id)->where('role', BusinessRole::Staff)->delete() !== 1) {
                return false;
            }

            $this->context->bypass(function () use ($membership): void {
                $ended = Stamper::query()->current()->where('business_id', $membership->business_id)->where('armed_by', $membership->user_id)
                    ->get()->reject(fn (Stamper $stamper): bool => $this->armingCounts->handle($stamper))->modelKeys();

                Stamper::query()->whereKey($ended)->update(Stamper::DISARMED);
            });

            return true;
        });
    }

    private function lockStampers(int $businessId): void
    {
        $this->context->bypass(fn () => Stamper::query()->current()->where('business_id', $businessId)->inLockOrder()->pluck('id'));
    }
}
