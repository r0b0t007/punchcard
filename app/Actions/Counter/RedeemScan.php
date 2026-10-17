<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Actions\Rewards\RedeemPresence;
use App\Actions\Rewards\RedeemRefused;
use App\Actions\Rewards\RedeemResult;
use App\Actions\Rewards\RedeemReward;
use App\Enums\RewardStatus;
use App\Models\CardEnrollment;
use App\Models\CounterScan;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a reward of the customer a staff member scanned (CHW-30, S3): the
 * scan, still running and by the staff member who scanned, is the presence
 * proof (RedeemPresence::scan), and RedeemReward does the rest: only that
 * customer's reward (NotYours otherwise), on a card the business honours,
 * once (a second time returns the first redemption). A retry after the scan
 * ran out still gets the redemption this scan made, as a used scan's stamp
 * does (StampScan); anything else then needs a new scan.
 */
final readonly class RedeemScan
{
    public function __construct(private RedeemReward $redeemReward, private TenantContext $context) {}

    /**
     * @throws ScanRefused
     * @throws RedeemRefused
     */
    public function handle(CounterScan $scan, User $staff, Reward $reward): RedeemResult
    {
        return DB::transaction(function () use ($scan, $staff, $reward): RedeemResult {
            $locked = CounterScan::query()->whereKey($scan->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->staff_id !== $staff->id) {
                throw new ScanRefused(ScanRefusal::NotYours);
            }

            if ($locked->hasExpired()) {
                return $this->redeemedBy($locked, $reward) ?? throw new ScanRefused(ScanRefusal::Expired);
            }

            $customer = $this->context->bypass(fn (): User => User::query()->findOrFail($locked->user_id));

            return $this->redeemReward->handle($reward, $customer, RedeemPresence::scan($locked));
        });
    }

    /** The redemption this scan made of the reward, if it did: its customer's, by its staff member, there, while it ran. */
    private function redeemedBy(CounterScan $scan, Reward $reward): ?RedeemResult
    {
        return $this->context->bypass(function () use ($scan, $reward): ?RedeemResult {
            $current = Reward::query()->find($reward->id);

            $madeByScan = $current instanceof Reward
                && $current->status === RewardStatus::Redeemed
                && CardEnrollment::query()->whereKey($current->enrollment_id)->where('user_id', $scan->user_id)->exists()
                && $current->redeemed_by === $scan->staff_id
                && $current->redeemed_location_id === $scan->location_id
                && $current->redeemed_at?->betweenIncluded($scan->scanned_at, $scan->expires_at) === true;

            return $madeByScan ? new RedeemResult($current, redeemedNow: false) : null;
        });
    }
}
