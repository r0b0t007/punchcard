<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\CustomerSegment;
use App\Enums\CustomerStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A customer's visits summarised from the ledger (CHW-44 at a business, CHW-365 across a franchise's sites): their
 * status at a moment and the list's tabs, each a range on an index. Written only by SummariseCustomer, in bypass().
 *
 * @phpstan-require-extends Model
 *
 * @property int $visits
 * @property Carbon $first_visit_at
 * @property Carbon $last_visit_at
 * @property Carbon $quiet_at
 * @property Carbon $lost_at
 */
trait SummarisesVisits
{
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
     * The customers in a tab at that moment.
     *
     * @param  Builder<static>  $query
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
            throw new LogicException('Customers are summarised from the ledger only, by SummariseCustomer in TenantContext::bypass().');
        }
    }
}
