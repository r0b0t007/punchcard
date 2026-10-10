<?php

declare(strict_types=1);

namespace App\Actions\Kits;

use App\Enums\KitOrderStatus;
use App\Models\Business;
use App\Models\KitOrder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Where to ship a business's stamper kit (CHW-31): its requested order,
 * created or, while still only requested, corrected; for its first open
 * site. Fulfilment takes it from there (CHW-57). Runs in the business's
 * tenant: KitOrder's guard decides.
 */
final readonly class RequestKit
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  array{recipient_name: string, phone: string, address: string, city: string, postal_code: ?string}  $shipping
     */
    public function handle(Business $business, array $shipping): KitOrder
    {
        return DB::transaction(function () use ($business, $shipping): KitOrder {
            // The business first, as CompleteStep locks it: a second request waits, then updates this order.
            $this->context->bypass(fn (): Business => Business::query()->lock('for no key update')->findOrFail($business->id));

            $order = KitOrder::query()
                ->where('business_id', $business->id)
                ->where('status', KitOrderStatus::Requested)
                ->lockForUpdate()
                ->first()
                ?? new KitOrder;

            $order->forceFill(['business_id' => $business->id, 'organization_id' => $business->organization_id]);
            $order->fill([...$shipping, 'location_id' => $business->firstLocation()->value('id')])->save();

            return $order;
        });
    }
}
