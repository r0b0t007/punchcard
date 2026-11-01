<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Support\Tenancy\TenantContext;

/**
 * A scheduled campaign canceled by whoever runs its business (CHW-42), until
 * it goes: one already due is on its way, and its delivery's claim and this
 * are one conditional write each, so exactly one of them wins. Its status is
 * its delivery's, written in bypass(); the caller checked the campaign is
 * the business's (CampaignPolicy::cancel). False when it had already gone.
 */
final readonly class CancelCampaign
{
    public function __construct(private TenantContext $context) {}

    public function handle(Campaign $campaign): bool
    {
        return $this->context->bypass(fn (): int => Campaign::query()->whereKey($campaign->id)
            ->where('status', CampaignStatus::Scheduled)
            ->where('send_at', '>', now())
            ->update(['status' => CampaignStatus::Canceled])) === 1;
    }
}
