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
 * The scheduler's pass over campaigns (CHW-42, every minute): each scheduled
 * one now due is queued for delivery (PushCampaign) inside its own business's
 * tenant, so QueuedTenant carries it (and fails it, canceling the campaign,
 * if that business closed meanwhile). One more than LATE_MINUTES late (the
 * scheduler was down) is canceled instead: a campaign never goes out late,
 * "Slow Tuesday?" on Wednesday. A send-now one queued meanwhile is queued
 * again harmlessly: delivery claims a campaign once.
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
        [$due, $missed] = $this->context->bypass(fn (): array => [
            Campaign::query()->where('status', CampaignStatus::Scheduled)
                ->where('send_at', '<=', $now)
                ->where('send_at', '>', $now->copy()->subMinutes(self::LATE_MINUTES))
                ->orderBy('send_at')
                ->get(['id', 'business_id', 'organization_id']),
            Campaign::query()->where('status', CampaignStatus::Scheduled)
                ->where('send_at', '<=', $now->copy()->subMinutes(self::LATE_MINUTES))
                ->update(['status' => CampaignStatus::Canceled]),
        ]);
        $queued = 0;

        foreach ($due as $campaign) {
            $business = $this->context->bypass(fn (): ?Business => Business::query()->with('organization')
                ->whereKey($campaign->business_id)->where('organization_id', $campaign->organization_id)->first());

            if (! $business instanceof Business) {
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
