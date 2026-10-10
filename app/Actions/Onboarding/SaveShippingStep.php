<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Kits\RequestKit;
use App\Enums\OnboardingStep;
use App\Models\Business;
use App\Models\KitOrder;
use Illuminate\Support\Facades\DB;

/**
 * The wizard's last step (CHW-31): where to ship the stamper kit
 * (RequestKit). With it, the business is set up (CompleteStep).
 */
final readonly class SaveShippingStep
{
    public function __construct(
        private RequestKit $requestKit,
        private CompleteStep $completeStep,
    ) {}

    /**
     * @param  array{recipient_name: string, phone: string, address: string, city: string, postal_code: ?string}  $shipping
     */
    public function handle(Business $business, array $shipping): KitOrder
    {
        return DB::transaction(function () use ($business, $shipping): KitOrder {
            $order = $this->requestKit->handle($business, $shipping);
            $this->completeStep->handle($business, OnboardingStep::Shipping);

            return $order;
        });
    }
}
