<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\StampEvent;
use App\Models\User;
use App\Policies\Concerns\ReadsProgram;

/**
 * The stamp ledger (CHW-22). A business's stamps are for its owner or org
 * admin (HQ sees every franchisee's). Anyone working there gives a manual
 * stamp or takes stamps back, as AddStamps decides: on a customer the
 * business can see, a manual stamp on a card it honours, and staff limited to
 * a site only there (an owner or org admin anywhere). Nobody changes or deletes a stamp: the ledger is
 * append-only, enforced by StampEvent and the database.
 */
final class StampEventPolicy
{
    use ReadsProgram;

    public function viewAny(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    public function view(User $user, StampEvent $event): bool
    {
        return $this->runs($event->organization_id, $event->business_id);
    }

    /** On a card the business honours. */
    public function createManual(User $user, Location $location, CardEnrollment $enrollment): bool
    {
        return $this->stampsAt($location, $enrollment) && $this->honours($enrollment->card_id, $location->business_id);
    }

    /** Also where the card is no longer honoured: the ledger stays fixable where stamps were given (AddStamps). */
    public function correct(User $user, Location $location, CardEnrollment $enrollment): bool
    {
        return $this->stampsAt($location, $enrollment);
    }

    private function stampsAt(Location $location, CardEnrollment $enrollment): bool
    {
        $limitedTo = $this->tenant()->isOrgAdmin() ? null : $this->tenant()->locationId();

        // The same scoped check as AddStamps: a customer the business can see.
        return $this->worksIn($location->business_id)
            && ($limitedTo === null || $limitedTo === $location->id)
            && CardEnrollment::query()->whereKey($enrollment->id)->exists();
    }
}
