<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\PushCampaign;
use App\Models\Business;
use App\Models\Campaign;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;

/**
 * The scheduler's pass over campaigns (CHW-42, every minute):
 *
 * - Each scheduled one now due and not queued yet is claimed (queued_at, one
 *   conditional write, so a backlog never queues it twice) and queued for
 *   delivery (PushCampaign) inside its own business's tenant, so QueuedTenant
 *   carries it (and fails it, canceling the campaign, if that business closed
 *   meanwhile). One sent now was queued as it was made.
 * - One more than LATE_MINUTES past its time is canceled: a campaign never
 *   goes out late ("Slow Tuesday?" on Wednesday), whether the scheduler or
 *   the queue was down; delivery refuses it too (DeliverCampaign).
 *
 * In bypass() to find them across businesses; each queued from its tenant.
 */
final readonly class DispatchDueCampaigns
{
    public const int LATE_MINUTES = 60;

    public function __construct(private TenantContext $context) {}

    /** @return array{queued: int, missed: int} */
    public function handle(CarbonInterface $now): array
    {
        $late = $now->copy()->subMinutes(self::LATE_MINUTES);
        [$due, $missed] = $this->context->bypass(fn (): array => [
            Campaign::query()->where('status', CampaignStatus::Scheduled)
                ->whereNull('queued_at')
                ->where('send_at', '<=', $now)
                ->where('send_at', '>', $late)
                ->orderBy('send_at')
                ->get(['id', 'business_id', 'organization_id']),
            Campaign::query()->where('status', CampaignStatus::Scheduled)
                ->where('send_at', '<=', $late)
                ->update(['status' => CampaignStatus::Canceled]),
        ]);
        $businesses = $this->context->bypass(fn () => Business::query()->with('organization')
            ->whereKey($due->pluck('business_id')->unique()->values()->all())->get()->keyBy('id'));
        $queued = 0;

        foreach ($due as $campaign) {
            $business = $businesses->get($campaign->business_id);
            $claimed = $this->context->bypass(fn (): int => Campaign::query()->whereKey($campaign->id)
                ->where('status', CampaignStatus::Scheduled)->whereNull('queued_at')
                ->update(['queued_at' => $now]));

            if (! $business instanceof Business || $business->organization_id !== $campaign->organization_id || $claimed !== 1) {
                continue;
            }

            $previous = $this->context->snapshot();

            try {
                $this->context->set($business->organization, $business);
                PushCampaign::dispatch($campaign->id);
                $queued++;
            } finally {
                $this->context->restore($previous);
            }
        }

        return ['queued' => $queued, 'missed' => $missed];
    }
}
