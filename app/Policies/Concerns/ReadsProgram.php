<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\StampSource;
use App\Models\CardBusiness;
use App\Models\StampEvent;

/**
 * The card program around the tenant (ADR 0006, CHW-22): which businesses
 * honour a card, and which customers a business has seen. Reads in bypass():
 * the answer must not depend on the caller's own scope.
 */
trait ReadsProgram
{
    use ReadsTenant;

    /** The business honours the card (card_business). */
    private function honours(int $cardId, int $businessId): bool
    {
        return $this->tenant()->bypass(fn (): bool => CardBusiness::query()
            ->where('card_id', $cardId)
            ->where('business_id', $businessId)
            ->exists());
    }

    /**
     * The customer was at the business: a stamp proving presence there, as
     * CardEnrollment::constrainToBusiness decides (a bonus, birthday or
     * referral stamp does not count).
     */
    private function visited(int $enrollmentId, int $businessId): bool
    {
        return $this->tenant()->bypass(fn (): bool => StampEvent::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('business_id', $businessId)
            ->whereIn('source', StampSource::presenceValues())
            ->exists());
    }

    /** The user manages customers where they work now: the org admin, or the owner of the business. */
    private function managesCustomers(): bool
    {
        $organizationId = $this->tenant()->organizationId();
        $businessId = $this->tenant()->businessId();

        return $organizationId !== null
            && ($this->administers($organizationId) || ($businessId !== null && $this->runs($organizationId, $businessId)));
    }

    /** The user runs the business they work in, and the customer was there. */
    private function runsWhereSeen(int $enrollmentId): bool
    {
        $businessId = $this->tenant()->businessId();
        $organizationId = $this->tenant()->organizationId();

        return $businessId !== null && $organizationId !== null
            && $this->runs($organizationId, $businessId)
            && $this->visited($enrollmentId, $businessId);
    }
}
