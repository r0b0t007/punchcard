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
 * "Redeem now" (C3/C4, CHW-26): opens a SECONDS window on one of the
 * customer's available rewards; their next verified tap inside it, at a
 * business that honours the card, redeems it (ApplyTap, RedeemReward).
 *
 * One window per customer: opening one closes any other, under a lock on the
 * customer's row (then the reward), so two opens at once cannot leave two.
 * Opening it again (try again) gives it SECONDS more; while it is still open
 * it keeps its start, so a tap made under it and claimed later still counts.
 * Needs a verified email. In bypass(): the customer has no tenant, and the
 * rewards are theirs.
 */
final readonly class OpenRedeemWindow
{
    public const int SECONDS = 60;

    public function __construct(private TenantContext $context) {}

    public function handle(Reward $reward, User $user): Reward
    {
        return DB::transaction(fn (): Reward => $this->context->bypass(function () use ($reward, $user): Reward {
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');
            $locked = Reward::query()->ownedBy($user)->whereKey($reward->id)->lockForUpdate()->first()
                ?? throw new RedeemRefused(RedeemRefusal::NotYours);

            if ($locked->status !== RewardStatus::Available) {
                throw new RedeemRefused(RedeemRefusal::Unavailable);
            }

            if (! $user->hasVerifiedEmail()) {
                throw new RedeemRefused(RedeemRefusal::Unverified);
            }

            Reward::query()
                ->ownedBy($user)
                ->whereKeyNot($locked->id)
                ->whereNotNull('redeem_window_until')
                ->update(['redeem_window_opened_at' => null, 'redeem_window_until' => null]);

            $open = $locked->redeem_window_until?->isFuture() === true;

            $locked->forceFill([
                'redeem_window_opened_at' => $open ? $locked->redeem_window_opened_at : now(),
                'redeem_window_until' => now()->addSeconds(self::SECONDS),
            ])->save();

            return $locked;
        }));
    }
}
