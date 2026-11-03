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
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A day a customer came in to a site (CHW-45, B3): at least one presence
 * stamp there (a tap, a scan, a stamp by hand) on that local day, in the
 * site's time, as the daily cap counts it. Site data: in a franchise, A1's
 * rows are only visits to A1. Read from the ledger, written only with the
 * customer's summary (SummariseCustomer), in bypass(); the ledger stays the
 * truth.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $location_id
 * @property int $user_id
 * @property Carbon $visited_on the site's local day
 * @property Carbon $last_at the day's latest presence stamp there
 */
#[Fillable(['organization_id', 'business_id', 'location_id', 'user_id', 'visited_on', 'last_at'])]
#[UseEloquentBuilder(TenantBuilder::class)]
#[WithoutTimestamps]
class CustomerVisitDay extends Model implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /** @param  array<string, mixed>  $values */
    public function assertTenantInsert(array $values): void
    {
        $this->assertSiteDataInsert($values);
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visited_on' => 'date',
            'last_at' => 'datetime',
        ];
    }

    private function assertSummarising(): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('Visit days are read from the ledger only, by SummariseCustomer in TenantContext::bypass().');
        }
    }
}
