<?php

declare(strict_types=1);

namespace App\Actions\Push;

use App\Models\User;

/**
 * Whether a tap's screen asks for notifications under it (C3, CHW-41 11a),
 * and what the ask needs (DescribePush): only a reward just unlocked, only
 * for the signed-in customer, and only while their "Rewards ready" is on,
 * since turning a phone on would not bring them otherwise.
 */
final readonly class DescribeRewardAsk
{
    public function __construct(private DescribePush $describePush) {}

    /**
     * @param  array{component: string, props: array<string, mixed>}  $screen  DescribeTap's
     * @return array{publicKey: string|null, rewards: bool, phones: list<string>}|null
     */
    public function handle(array $screen, ?User $user): ?array
    {
        // Only the stamp screen (C3) carries rewards: the ones this tap unlocked.
        if (($screen['props']['rewards'] ?? []) === [] || ! $user?->push_rewards) {
            return null;
        }

        return $this->describePush->handle($user);
    }
}
