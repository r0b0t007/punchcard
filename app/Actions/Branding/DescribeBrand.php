<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;

/**
 * The brand page (CHW-135, B17, design 24): the organization's logo, colour
 * and cover, and what the previews draw. The name on them is the business's
 * inside an independent café or chain (its only business, as customers see
 * it), else the organization's. The sample card is the program's first active
 * card, or none (the page draws a plain one). A franchise's head office also
 * reads how many sites show the brand: those operating, neither closed nor
 * suspended.
 */
final readonly class DescribeBrand
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{
     *     brand: array{name: string, franchise: bool, logoUrl: ?string, coverUrl: ?string, brandColor: ?string, brandForeground: ?string},
     *     sample: array{cardName: string, rewardText: string, stampsRequired: int, stampStyle: ?string}|null,
     *     sites: int|null,
     * }
     */
    public function handle(Organization $organization): array
    {
        $businessId = $this->context->businessId();
        $businessName = $businessId === null ? null : Business::query()->whereKey($businessId)->value('name');
        $franchise = $organization->isFranchise();
        $card = LoyaltyCard::query()->where('active', true)->oldest('id')
            ->first(['id', 'name', 'reward_text', 'stamps_required', 'stamp_style']);

        return [
            'brand' => [
                'name' => is_string($businessName) ? $businessName : $organization->name,
                'franchise' => $franchise,
                'logoUrl' => $organization->logoUrl(),
                'coverUrl' => $organization->coverUrl(),
                'brandColor' => $organization->brand_color,
                'brandForeground' => $organization->brandForeground(),
            ],
            'sample' => $card instanceof LoyaltyCard ? [
                'cardName' => $card->name,
                'rewardText' => $card->reward_text,
                'stampsRequired' => $card->stamps_required,
                'stampStyle' => $card->stamp_style,
            ] : null,
            'sites' => $franchise ? Business::query()->operating()->count() : null,
        ];
    }
}
