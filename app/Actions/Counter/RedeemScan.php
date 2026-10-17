<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Actions\Rewards\RedeemPresence;
use App\Actions\Rewards\RedeemRefused;
use App\Actions\Rewards\RedeemResult;
use App\Actions\Rewards\RedeemReward;
use App\Models\CounterScan;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a reward of the customer a staff member scanned (CHW-30, S3): the
 * scan, still running and by the staff member who scanned while they still
 * work there (LockScan), is the presence proof (RedeemPresence::scan), and
 * RedeemReward does the rest: only that customer's reward (NotYours
 * otherwise), on a card the business honours, once each. While the scan
 * runs, it redeems each reward the customer asks for: a rescan of them is
 * refused for a while (MemberQrScan's claim), so one reward per scan would
 * keep them waiting. Each reward records the scan (redeemed_scan_id), so a
 * retry after the scan ran out gets that redemption again, as a used scan's
 * stamp does (StampScan), and nothing else.
 */
final readonly class RedeemScan
{
    public function __construct(
        private RedeemReward $redeemReward,
        private LockScan $lockScan,
        private TenantContext $context,
    ) {}

    /**
     * @throws ScanRefused
     * @throws RedeemRefused
     */
    public function handle(CounterScan $scan, User $staff, Reward $reward): RedeemResult
    {
        return DB::transaction(function () use ($scan, $staff, $reward): RedeemResult {
            $locked = $this->lockScan->handle($scan, $staff);
            $presence = RedeemPresence::scan($locked);

            if (! $presence instanceof RedeemPresence) {
                return $this->redeemedWith($locked, $reward) ?? throw new ScanRefused(ScanRefusal::Expired);
            }

            $customer = $this->context->bypass(fn (): User => User::query()->findOrFail($locked->user_id));

            return $this->redeemReward->handle($reward, $customer, $presence);
        });
    }

    /** The redemption this scan made of the reward, for a retry after the scan ran out (recorded on it: redeemed_scan_id). */
    private function redeemedWith(CounterScan $scan, Reward $reward): ?RedeemResult
    {
        $current = $this->context->bypass(fn (): ?Reward => Reward::query()->find($reward->id));

        return $current instanceof Reward && $current->redeemed_scan_id === $scan->id ? new RedeemResult($current, redeemedNow: false) : null;
    }
}
