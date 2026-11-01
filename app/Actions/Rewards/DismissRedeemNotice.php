<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Got it on My cards' notice (CHW-228, design 14b): the customer was told
 * staff redeemed this reward with a scan. Only one of their own rewards
 * redeemed by a scan; false for anything else (another customer's, a tap's),
 * which the caller answers as not found. In bypass(), kept to their
 * enrollments.
 */
final readonly class DismissRedeemNotice
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user, int $rewardId): bool
    {
        return $this->context->bypass(function () use ($user, $rewardId): bool {
            $theirs = Reward::query()->whereKey($rewardId)
                ->whereIn('enrollment_id', CardEnrollment::query()->select('id')->where('user_id', $user->id))
                ->where('redeemed_by_scan', true);

            if (! (clone $theirs)->exists()) {
                return false;
            }

            $theirs->whereNull('redeem_notice_at')->update(['redeem_notice_at' => now()]);

            return true;
        });
    }
}
