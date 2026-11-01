<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Business;
use App\Models\Campaign;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;

/**
 * How many campaigns a business may still send (CHW-42): at most
 * punchcard.campaigns.per_week in any 7 days, so followers keep wanting to
 * hear from it, canceled ones aside.
 *
 * - used: the most campaigns already in one 7-day window that would also
 *   hold one going at $at (the busiest week it would join);
 * - nextAt, once none is left: the first moment a campaign leaves those
 *   windows and leaves room, later campaigns already scheduled counted in.
 */
final readonly class CampaignLimit
{
    public const int DAYS = 7;

    public function __construct(private TenantContext $context) {}

    /** @return array{used: int, max: int, nextAt: CarbonInterface|null} */
    public function handle(Business $business, CarbonInterface $at): array
    {
        $max = (int) config('punchcard.campaigns.per_week');
        /** @var list<CarbonInterface> $times */
        $times = $this->context->bypass(fn (): array => Campaign::query()
            ->where('business_id', $business->id)
            ->where('status', '!=', CampaignStatus::Canceled)
            ->where('send_at', '>', $at->copy()->subDays(self::DAYS))
            ->orderBy('send_at')
            ->pluck('send_at')->all());
        $used = $this->busiest($times, $at);
        $nextAt = null;

        // Room only comes back when a campaign leaves the window: one of these moments.
        foreach ($used >= $max ? $times : [] as $time) {
            $leaves = $time->copy()->addDays(self::DAYS);

            if ($leaves->greaterThan($at) && $this->busiest($times, $leaves) < $max) {
                $nextAt = $leaves;

                break;
            }
        }

        return ['used' => $used, 'max' => $max, 'nextAt' => $nextAt];
    }

    /**
     * The most of these campaigns in one 7-day window [start, start + 7 days)
     * that also holds $at: one starting at $at, or at a campaign before it.
     *
     * @param  list<CarbonInterface>  $times
     */
    private function busiest(array $times, CarbonInterface $at): int
    {
        $earliest = $at->copy()->subDays(self::DAYS);
        $starts = [$at, ...array_filter($times, fn (CarbonInterface $time): bool => $time->greaterThan($earliest) && $time->lessThanOrEqualTo($at))];

        return max(array_map(fn (CarbonInterface $start): int => count(array_filter(
            $times,
            fn (CarbonInterface $time): bool => $time->greaterThanOrEqualTo($start) && $time->lessThan($start->copy()->addDays(self::DAYS)),
        )), $starts));
    }
}
