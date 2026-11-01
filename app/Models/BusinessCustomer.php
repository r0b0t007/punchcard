<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerSegment;
use App\Enums\CustomerStatus;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A customer of a business (CHW-44, B4, B5): someone who came in, with a
 * presence stamp there, and what that adds up to. Site data: in a franchise,
 * A1's rows are only who visited A1, the org admin sees the organization's.
 * A summary of the ledger, written only by SummariseCustomer, in bypass();
 * the ledger stays the truth.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $user_id
 * @property int $visits days with a presence stamp here, in the stamp's site's time
 * @property Carbon $first_visit_at
 * @property Carbon $last_visit_at
 * @property int|null $usual_gap_days days between their visits on average; null before a second visit
 * @property Carbon $quiet_at when they go quiet if they don't come back: twice their usual gap, at least quiet_min_days
 * @property Carbon $lost_at when they are lost if they don't come back: lost_days after their last visit
 * @property int $rewards_redeemed rewards they redeemed here
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'business_id', 'user_id', 'visits', 'first_visit_at', 'last_visit_at', 'usual_gap_days', 'quiet_at', 'lost_at', 'rewards_redeemed'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class BusinessCustomer extends Model implements TenantModel
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

    /** Lost first, then going quiet, else active, at that moment. */
    public function status(CarbonInterface $now): CustomerStatus
    {
        return match (true) {
            $this->lost_at->lessThanOrEqualTo($now) => CustomerStatus::Lost,
            $this->quiet_at->lessThanOrEqualTo($now) => CustomerStatus::Quiet,
            default => CustomerStatus::Active,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The customers in a tab at that moment.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inSegment(Builder $query, CustomerSegment $segment, CarbonInterface $now): void
    {
        match ($segment) {
            CustomerSegment::All => null,
            CustomerSegment::Regulars => $query->where('visits', '>=', (int) config('punchcard.customers.regular_visits'))->where('quiet_at', '>', $now),
            CustomerSegment::Quiet => $query->where('quiet_at', '<=', $now)->where('lost_at', '>', $now),
            CustomerSegment::Lost => $query->where('lost_at', '<=', $now),
            CustomerSegment::New => $query->where('first_visit_at', '>', $now->copy()->subDays((int) config('punchcard.customers.new_days'))),
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_visit_at' => 'datetime',
            'last_visit_at' => 'datetime',
            'quiet_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    private function assertSummarising(): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('A business\'s customers are summarised from the ledger only, by SummariseCustomer in TenantContext::bypass().');
        }
    }
}
