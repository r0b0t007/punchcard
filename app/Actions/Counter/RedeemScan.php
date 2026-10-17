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
 * Redeems one reward of the customer a staff member scanned (CHW-30, S3):
 * the scan, still running and by the staff member who scanned while they
 * still work there (LockScan), is the presence proof (RedeemPresence::scan),
 * and RedeemReward does the rest: only that customer's reward (NotYours
 * otherwise), on a card the business honours. One redemption per scan: the
 * customer showed their code once, so another reward needs another scan.
 * The scan records it (reward_id), so a retry of the same reward, even
 * after the scan ran out, gets that redemption again, as a used scan's stamp
 * does (StampScan).
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

            if ($locked->reward_id !== null) {
                return $locked->reward_id === $reward->id
                    ? new RedeemResult($this->context->bypass(fn (): Reward => Reward::query()->findOrFail($reward->id)), redeemedNow: false)
                    : throw new ScanRefused(ScanRefusal::Used);
            }

            $presence = RedeemPresence::scan($locked) ?? throw new ScanRefused(ScanRefusal::Expired);
            $customer = $this->context->bypass(fn (): User => User::query()->findOrFail($locked->user_id));
            $result = $this->redeemReward->handle($reward, $customer, $presence);

            if ($result->redeemedNow) {
                $locked->forceFill(['reward_id' => $result->reward->id])->save();
            }

            return $result;
        });
    }
}
