<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\Business;
use App\Models\KitOrder;
use App\Models\User;

/**
 * What the wizard's shipping step shows (CHW-31): the business's requested
 * kit order, or a start for one: the owner's name and the first site's
 * address. Read in the business's tenant.
 *
 * @phpstan-type Shipping array{order: ?KitOrder, recipientName: ?string, phone: ?string, address: ?string, city: ?string, postalCode: ?string}
 */
final readonly class DescribeShippingStep
{
    /** @return Shipping */
    public function handle(Business $business, User $owner): array
    {
        $order = KitOrder::query()->where('business_id', $business->id)->requested()->first();

        return [
            'order' => $order,
            'recipientName' => $order->recipient_name ?? $owner->name,
            'phone' => $order?->phone,
            'address' => $order->address ?? $business->firstLocation?->address,
            'city' => $order?->city,
            'postalCode' => $order?->postal_code,
        ];
    }
}
