<?php

declare(strict_types=1);

namespace App\Actions\Push;

use App\Models\User;
use App\Support\Push\PushKey;

/**
 * What a page that offers notifications needs (CHW-41): C12's Notifications
 * (11c), and the ask under a reward unlocked (C3) or a place just followed
 * (C6, 11a).
 *
 * - publicKey: the app's VAPID key to subscribe with; none set, none offered;
 * - rewards: the account's "Rewards ready";
 * - phones: the account's phones as SHA-256 of their endpoints, never the
 *   endpoints (they work like a password to the push service). This phone
 *   is "On" only if it is one of them, so the same phone signed in as
 *   someone else reads "Off" (lib/push.ts).
 */
final readonly class DescribePush
{
    /** @return array{publicKey: string|null, rewards: bool, phones: list<string>} */
    public function handle(User $user): array
    {
        return [
            'publicKey' => PushKey::public(),
            'rewards' => $user->push_rewards,
            'phones' => array_values(array_map(fn (string $endpoint): string => hash('sha256', $endpoint),
                $user->pushSubscriptions()->orderBy('id')->pluck('endpoint')->all())),
        ];
    }
}
