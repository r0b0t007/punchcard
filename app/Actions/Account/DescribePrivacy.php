<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;

/**
 * The Privacy settings page (CHW-58, C12, review 7a): punchcard's news, on
 * since or off since a day (UTC), or never chosen, and whether the email
 * must be confirmed before turning it on.
 */
final readonly class DescribePrivacy
{
    /**
     * @return array{marketing: array{on: bool, since: string|null, mustVerify: bool}}
     */
    public function handle(User $user): array
    {
        $on = $user->marketing_opt_in_at !== null;

        return [
            'marketing' => [
                'on' => $on,
                'since' => ($on ? $user->marketing_opt_in_at : $user->marketing_opt_out_at)?->toDateString(),
                'mustVerify' => ! $user->hasVerifiedEmail(),
            ],
        ];
    }
}
