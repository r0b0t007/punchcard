<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRequest;
use App\Actions\Stamps\StampResult;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;

/**
 * Stamps added by hand for one of a business's customers, or taken back
 * (CHW-44, B5): AddStamps as the owner, then their summary at once, so the
 * page shows the visit straight away, and a customer whose every stamp here
 * was taken back is no longer listed. The queued SummariseCustomers runs
 * after it too, to the same result.
 */
final readonly class StampCustomer
{
    public function __construct(private AddStamps $addStamps, private SummariseCustomer $summariseCustomer) {}

    /**
     * @return array{0: StampResult, 1: bool} the stamp, and whether they are still the business's customer
     */
    public function handle(BusinessCustomer $customer, CardEnrollment $enrollment, StampRequest $request): array
    {
        $result = $this->addStamps->handle($enrollment, $request);
        $this->summariseCustomer->handle($customer->business_id, $customer->organization_id, $customer->user_id);

        return [$result, BusinessCustomer::query()->whereKey($customer->id)->exists()];
    }
}
