<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ChangedOnlyByOrgAdmin;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A card_business row: a business that honours one of its organization's
 * cards. Program data, changed only by an org admin; LoyaltyCard::businesses()
 * uses this pivot so every attach, detach and sync goes through those checks.
 * Every business of the organization reads these rows, so a franchisee sees
 * which sibling business ids honour the card (not the businesses themselves,
 * which stay behind their own scope): ADR 0006 does not hide participation.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $card_id
 * @property int $business_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('card_business', incrementing: true)]
#[UseEloquentBuilder(TenantBuilder::class)]
class CardBusiness extends Pivot implements TenantModel
{
    use ChangedOnlyByOrgAdmin {
        assertTenantWrite as assertProgramWrite;
    }
    use GuardsTenantWrites;

    /**
     * organization_id is the card's, also in bypass() where there is no tenant to
     * take it from: the card attach() was called on, or one lookup without it.
     */
    public function fillTenantColumns(): void
    {
        if ($this->getAttribute('organization_id') !== null || $this->getAttribute('card_id') === null) {
            return;
        }

        if ($this->pivotParent instanceof LoyaltyCard && $this->pivotParent->getKey() === $this->getAttribute('card_id')) {
            $this->setAttribute('organization_id', $this->pivotParent->organization_id);

            return;
        }

        $this->fillOrganizationFrom(LoyaltyCard::class, 'card_id');
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (array_key_exists('card_id', $values)) {
            throw new LogicException('A participation cannot move to another card: detach and attach instead.');
        }

        $this->assertProgramWrite($operation, $values);
    }
}
