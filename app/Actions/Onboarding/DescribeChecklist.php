<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Tap;
use App\Support\Tenancy\TenantContext;

/**
 * The setup checklist on the owner's dashboard (CHW-31, B2): what is left
 * before the first customers, each item ticked by what happened. The QR
 * stand was opened to print; the stamper is placed once it has been tapped,
 * whatever the tap gave (a verified tap is the business's in the tap log; a
 * stamp by hand proves nothing about it); staff are invited once one has
 * joined (invitations, CHW-146). Read in the business's tenant.
 */
final readonly class DescribeChecklist
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{qrStand: bool, stamperPlaced: bool, staffInvited: bool}
     */
    public function handle(Business $business): array
    {
        return [
            'qrStand' => $business->qr_stand_opened_at !== null,
            'stamperPlaced' => $this->context->bypass(fn (): bool => Tap::query()->where('business_id', $business->id)->exists()),
            'staffInvited' => BusinessMember::query()->where('business_id', $business->id)->where('role', BusinessRole::Staff)->exists(),
        ];
    }
}
