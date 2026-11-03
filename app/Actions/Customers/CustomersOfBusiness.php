<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\StampSource;
use App\Models\CardEnrollment;
use App\Models\StampEvent;
use App\Support\Tenancy\TenantContext;

/**
 * Who came in to a business, with a presence stamp there (CHW-44): the
 * customers it has, by user id, for summarising them all again. In
 * bypass(), kept to the business: in a franchise, A2's customers are never
 * A1's.
 */
final readonly class CustomersOfBusiness
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return list<int>
     */
    public function handle(int $businessId): array
    {
        return array_values($this->context->bypass(fn (): array => CardEnrollment::query()
            ->whereIn('id', StampEvent::query()->select('enrollment_id')->where('business_id', $businessId)->whereIn('source', StampSource::presenceValues()))
            ->distinct()->orderBy('user_id')->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all()));
    }
}
