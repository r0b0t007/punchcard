<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\User;

/**
 * "Add to my cards" on a join page (CHW-31): the customer holds the
 * business's card, as a first tap would enrol them (EnrollCustomer, once),
 * but with no stamp: only a tap or a staff scan proves presence. Nothing
 * when the page offers nothing (DescribeJoinPage: the card is switched off).
 */
final readonly class JoinCard
{
    public function __construct(
        private DescribeJoinPage $describeJoinPage,
        private EnrollCustomer $enrollCustomer,
    ) {}

    public function handle(Business $business, User $customer): ?CardEnrollment
    {
        return $this->describeJoinPage->handle($business, $customer)['available']
            ? $this->enrollCustomer->handle($business, $customer)
            : null;
    }
}
