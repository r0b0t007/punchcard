<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CardEnrollment;
use App\Models\User;
use App\Policies\Concerns\ReadsProgram;

/**
 * A customer's card in the portal (ADR 0006, CHW-22), as TenantScope shows
 * it: the org admin sees the whole program (from inside a business too, HQ
 * at its own site); the owner of a business sees the customers who were
 * there, so franchisee A1 never sees one who only went to A2. Staff see none
 * in the portal: at the counter, a customer's card comes from their scanned
 * member token (CHW-28/30), the scan being the proof they are there. The
 * customer reads their own through scoped lookups (Reward::ownedBy).
 */
final class CardEnrollmentPolicy
{
    use ReadsProgram;

    /** The customer list (B4): for the org admin, or the owner of the business worked in. */
    public function viewAny(): bool
    {
        return $this->managesCustomers();
    }

    public function view(User $user, CardEnrollment $enrollment): bool
    {
        return $this->administers($enrollment->organization_id) || $this->runsWhereSeen($enrollment->id);
    }
}
