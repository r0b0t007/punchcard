<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\RedeemRefusal;
use App\Enums\RewardStatus;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Back from the redeem screen (C4, CHW-26): closes the customer's redeem
 * window on that reward, so their next tap stamps instead of spending the
 * reward they changed their mind about. Locks as OpenRedeemWindow does (the
 * customer's row, then the reward). A redeemed reward, or one with no window
 * open, is left as it is.
 */
final readonly class CloseRedeemWindow
{
    public function __construct(private TenantContext $context) {}

    public function handle(Reward $reward, User $user): void
    {
        DB::transaction(fn () => $this->context->bypass(function () use ($reward, $user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');
            $locked = Reward::query()->ownedBy($user)->whereKey($reward->id)->lockForUpdate()->first()
                ?? throw new RedeemRefused(RedeemRefusal::NotYours);

            if ($locked->status === RewardStatus::Available && $locked->redeem_window_until !== null) {
                $locked->forceFill(['redeem_window_opened_at' => null, 'redeem_window_until' => null])->save();
            }
        }));
    }
}
