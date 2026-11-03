<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A business's figures for one ended local day (CHW-45, B3), at one of its
 * sites or, with no location, the whole business. Site data: in a
 * franchise, A1's rows are only A1's. Rolled up from the ledger by
 * RollUpDay, in bypass(); the ledger stays the truth.
 *
 * - visits: customers who came in (a presence stamp), each once, on
 *   their site's day;
 * - new_customers: of them, those who came in here for the first time;
 * - stamps: stamps given (presence and system), corrections aside;
 * - rewards_unlocked: rewards a stamp here unlocked;
 * - rewards_redeemed: rewards redeemed here.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int|null $location_id null: the whole business
 * @property Carbon $day the site's (or the business's) local day
 * @property string $timezone the day's time when it was rolled up
 * @property int $visits
 * @property int $new_customers
 * @property int $stamps
 * @property int $rewards_unlocked
 * @property int $rewards_redeemed
 */
#[Fillable(['organization_id', 'business_id', 'location_id', 'day', 'timezone', 'visits', 'new_customers', 'stamps', 'rewards_unlocked', 'rewards_redeemed'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class BusinessDailyStat extends Model implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /** @param  array<string, mixed>  $values */
    public function assertTenantInsert(array $values): void
    {
        $this->assertSiteDataInsert($values);
        $this->assertRollingUp();
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $this->assertRollingUp();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'visits' => 'integer',
            'new_customers' => 'integer',
            'stamps' => 'integer',
            'rewards_unlocked' => 'integer',
            'rewards_redeemed' => 'integer',
        ];
    }

    private function assertRollingUp(): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('Daily stats are rolled up from the ledger only, by RollUpDay in TenantContext::bypass().');
        }
    }
}
