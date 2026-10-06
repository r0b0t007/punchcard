<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\RedeemRefusal;
use App\Enums\RewardStatus;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * "Redeem now" (C3/C4, CHW-26): opens a SECONDS window on one of the
 * customer's available rewards; their next verified tap inside it, at a
 * business that honours the card, redeems it (ApplyTap, RedeemReward). One
 * window per customer: opening one closes any other, with all their rewards
 * locked (in id order), so two opens at once cannot leave two windows.
 * Opening it again (try again) restarts it. Needs a verified email. In
 * bypass(): the customer has no tenant, and the rewards are theirs.
 */
final readonly class OpenRedeemWindow
{
    public const int SECONDS = 60;

    public function __construct(private TenantContext $context) {}

    public function handle(Reward $reward, User $user): Reward
    {
        return DB::transaction(fn (): Reward => $this->context->bypass(function () use ($reward, $user): Reward {
            $own = Reward::query()
                ->whereIn('enrollment_id', CardEnrollment::query()->select('id')->where('user_id', $user->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = $own->firstWhere('id', $reward->id) ?? throw new RedeemRefused(RedeemRefusal::NotYours);

            if ($locked->status !== RewardStatus::Available) {
                throw new RedeemRefused(RedeemRefusal::Unavailable);
            }

            if (! $user->hasVerifiedEmail()) {
                throw new RedeemRefused(RedeemRefusal::Unverified);
            }

            Reward::query()
                ->whereKey($own->where('id', '!==', $locked->id)->whereNotNull('redeem_window_until')->modelKeys())
                ->update(['redeem_window_until' => null]);

            $locked->forceFill(['redeem_window_until' => now()->addSeconds(self::SECONDS)])->save();

            return $locked;
        }));
    }
}
