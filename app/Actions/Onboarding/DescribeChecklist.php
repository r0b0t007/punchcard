<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\BusinessRole;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessMember;
use App\Models\Tap;
use App\Support\Tenancy\TenantContext;

/**
 * The setup checklist on the owner's dashboard (CHW-31, B2): what is left
 * before the first customers, each item ticked by what happened. The QR
 * stand was printed; the stamper is placed once the counter has accepted a
 * tap on it (Tap::accepted: waiting, stamped or redeemed; a replayed URL or
 * a tap on a disabled stamper doesn't say it's at the till, and a stamp by
 * hand proves nothing about it); staff are invited once one has
 * joined (invitations, CHW-146); its documents are sent once both kinds
 * are waiting or accepted (CHW-145): a request for a new one unticks it.
 * Read in the business's tenant.
 */
final readonly class DescribeChecklist
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{qrStand: bool, stamperPlaced: bool, staffInvited: bool, documentsSent: bool}
     */
    public function handle(Business $business): array
    {
        return [
            'qrStand' => $business->qr_stand_opened_at !== null,
            'stamperPlaced' => $this->context->bypass(fn (): bool => Tap::query()->where('business_id', $business->id)->accepted()->exists()),
            'staffInvited' => BusinessMember::query()->where('business_id', $business->id)->where('role', BusinessRole::Staff)->exists(),
            'documentsSent' => $this->documentsSent($business),
        ];
    }

    /** Both kinds' latest document waiting or accepted, or the business verified already (without them, before CHW-145). */
    private function documentsSent(Business $business): bool
    {
        if ($business->verified_at !== null) {
            return true;
        }

        $latest = BusinessDocument::latestByKind($business->id);

        return $latest->count() === count(DocumentKind::cases())
            && $latest->every(fn (BusinessDocument $document): bool => $document->status !== DocumentStatus::Rejected);
    }
}
