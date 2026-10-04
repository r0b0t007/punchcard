<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Tap;
use App\Support\Tenancy\TenantContext;

/**
 * Marks the signed-out taps nobody claimed in time as expired (CHW-25), so
 * the tap log and the owner's fraud view never show them as still waiting.
 * ApplyTap expires one too when it is claimed late; this catches the rest.
 * Scheduled every five minutes.
 */
final readonly class ExpirePendingTaps
{
    public function __construct(private TenantContext $context) {}

    /** @return int how many taps expired */
    public function handle(): int
    {
        return $this->context->bypass(fn (): int => Tap::query()
            ->where('status', TapStatus::Pending)
            ->where('expires_at', '<=', now())
            ->update(['status' => TapStatus::Expired, 'rejection' => TapRejection::Expired]));
    }
}
