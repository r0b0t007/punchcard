<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StampSource;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use App\Support\Tenancy\TenantScope;
use App\Support\Tenancy\VisibleToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A customer's consent to hear about an organization's offers (CHW-27):
 * "Follow for offers" on a card's page. Customer data of the organization,
 * read like enrollments: its org admin sees every follower, a franchisee
 * only those who stamped there (VisibleToBusiness), another organization
 * none. Only the customer sets it (SetFollow, in bypass()); its customer
 * and program never change, and it goes with a deleted account.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property bool $notify
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'user_id', 'notify'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class Follow extends Model implements TenantModel, VisibleToBusiness
{
    use BelongsToOrganization {
        assertTenantInsert as assertProgramDataInsert;
    }
    use GuardsTenantWrites;

    /**
     * Set by its customer only, in bypass(), on an open organization.
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertProgramDataInsert($values);

        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('A follow is set by its customer (SetFollow), in TenantContext::bypass().');
        }

        ArchivedSites::assertOrganizationOpen($values['organization_id'] ?? null, 'A follow', lock: true);
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $fixed = array_intersect(array_keys($values), ['user_id', 'organization_id']);

        if ($fixed !== []) {
            throw new LogicException('A follow\'s '.implode(', ', $fixed).' cannot change.');
        }

        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('A follow changes only by its customer (SetFollow) or a deleted account, in TenantContext::bypass().');
        }
    }

    /**
     * A franchisee sees the followers who stamped there in person, on a card
     * of the program they follow, whoever asks (a bypassed job included).
     */
    public function constrainToBusiness(Builder $query, int $businessId): void
    {
        $query->whereIn($query->qualifyColumn('user_id'), CardEnrollment::query()
            ->select('card_enrollments.user_id')
            ->whereColumn('card_enrollments.organization_id', $query->qualifyColumn('organization_id'))
            ->whereHas('stampEvents', fn (Builder $events) => $events
                ->where('business_id', $businessId)
                ->whereIn('source', StampSource::presenceValues())));
    }

    /**
     * Customer data: VisibleToBusiness inside a business without org admin rights.
     *
     * @return TenantScope<Model>
     */
    protected static function tenantScope(): TenantScope
    {
        return new TenantScope(customerData: true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notify' => 'boolean',
        ];
    }
}
