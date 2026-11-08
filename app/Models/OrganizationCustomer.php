<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\GuardsTenantWrites;
use App\Models\Concerns\SummarisesVisits;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantModel;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer of a franchise across its sites (CHW-365, B4 and B5 at network level): someone who is a customer of
 * any of the organization's businesses (BusinessCustomer), and what that adds up to. A day at two sites is one visit;
 * they go quiet and are lost only when they stop coming to every site.
 *
 * Customer data of the organization, for its org admin alone: inside a business without org admin rights no row is
 * visible (TenantScope's customerData rule; not VisibleToBusiness), so franchisee A1 never sees who visited A2.
 * A summary of the ledger, written only by SummariseCustomer, in bypass().
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int $visits days with a presence stamp at any of its sites, each in that site's time
 * @property Carbon $first_visit_at
 * @property Carbon $last_visit_at
 * @property int|null $usual_gap_days days between their visits on average; null before a second visit
 * @property Carbon $quiet_at when they go quiet if they don't come back anywhere
 * @property Carbon $lost_at when they are lost if they don't come back anywhere
 * @property int $rewards_redeemed rewards they redeemed at its sites
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'user_id', 'visits', 'first_visit_at', 'last_visit_at', 'usual_gap_days', 'quiet_at', 'lost_at', 'rewards_redeemed'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class OrganizationCustomer extends Model implements TenantModel
{
    use BelongsToOrganization {
        assertTenantInsert as assertProgramDataInsert;
    }
    use GuardsTenantWrites;
    use SummarisesVisits;

    /** @param  array<string, mixed>  $values */
    public function assertTenantInsert(array $values): void
    {
        $this->assertProgramDataInsert($values);
        $this->assertSummarising();
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $this->assertSummarising();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Customer data: the org admin's alone; inside a business without org admin rights, none.
     *
     * @return TenantScope<Model>
     */
    protected static function tenantScope(): TenantScope
    {
        return new TenantScope(customerData: true);
    }
}
