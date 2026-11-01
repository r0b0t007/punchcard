<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Business;
use App\Models\Campaign;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How many campaigns a business may still send (CHW-42): at most
 * punchcard.campaigns.per_week in any 7 days, so followers keep wanting to
 * hear from it. A campaign going at $at counts the others going less than 7
 * days before or after it, canceled ones aside. nextAt: when the earliest of
 * them leaves the window, once none is left.
 */
final readonly class CampaignLimit
{
    public const int DAYS = 7;

    public function __construct(private TenantContext $context) {}

    /** @return array{used: int, max: int, nextAt: Carbon|null} */
    public function handle(Business $business, CarbonInterface $at): array
    {
        $max = (int) config('punchcard.campaigns.per_week');
        $near = $this->context->bypass(fn () => Campaign::query()
            ->where('business_id', $business->id)
            ->where('status', '!=', CampaignStatus::Canceled)
            ->where('send_at', '>', $at->copy()->subDays(self::DAYS))
            ->where('send_at', '<', $at->copy()->addDays(self::DAYS))
            ->orderBy('send_at')
            ->pluck('send_at'));

        $first = $near->first();

        return [
            'used' => $near->count(),
            'max' => $max,
            'nextAt' => $near->count() >= $max && $first instanceof Carbon ? $first->copy()->addDays(self::DAYS) : null,
        ];
    }
}
