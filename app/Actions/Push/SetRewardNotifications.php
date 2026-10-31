<?php

declare(strict_types=1);

namespace App\Actions\Push;

use App\Models\User;

/**
 * "Rewards ready" in Settings (CHW-41, 11c): whether the customer's phones
 * with notifications on hear about a reward unlocked. A service
 * notification, not marketing: on by default, no consent log.
 */
final readonly class SetRewardNotifications
{
    public function handle(User $user, bool $on): void
    {
        $user->forceFill(['push_rewards' => $on])->save();
    }
}
