<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * "Add to my cards" on a join page (CHW-31): the customer holds the
 * business's card, as a first tap would enrol them (EnrollCustomer, once),
 * but with no stamp: only a tap or a staff scan proves presence. Nothing
 * when the business has no active card.
 */
final readonly class JoinCard
{
    public function __construct(
        private TenantContext $context,
        private EnrollCustomer $enrollCustomer,
    ) {}

    public function handle(Business $business, User $customer): ?CardEnrollment
    {
        $available = $this->context->bypass(fn (): bool => LoyaltyCard::query()->honouredBy($business->id)->where('active', true)->exists());

        return $available ? $this->enrollCustomer->handle($business, $customer) : null;
    }
}
