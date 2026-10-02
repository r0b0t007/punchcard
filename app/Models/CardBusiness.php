<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A card_business row: a business that honours one of its organization's
 * cards. Program data, changed only by an org admin; LoyaltyCard::businesses()
 * uses this pivot so every attach, detach and sync goes through those checks.
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
    use BelongsToOrganization {
        assertTenantInsert as assertProgramDataInsert;
    }
    use GuardsTenantWrites;

    /** organization_id is the card's, also in bypass() where there is no tenant to take it from. */
    public function fillTenantColumns(): void
    {
        if ($this->getAttribute('organization_id') !== null || $this->getAttribute('card_id') === null) {
            return;
        }

        $organizationId = app(TenantContext::class)->bypass(
            fn (): mixed => LoyaltyCard::query()->whereKey($this->getAttribute('card_id'))->value('organization_id'),
        );

        $this->setAttribute('organization_id', $organizationId === null ? null : (int) $organizationId);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertProgramDataInsert($values);
        $this->assertOrgAdminChangesProgram();
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $this->assertOrgAdminChangesProgram();
    }
}
