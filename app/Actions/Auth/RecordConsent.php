<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Dates what a new account said on signing up (CHW-23, CNDP, ConsentRules):
 * 16 or older, and news by email only when ticked. Saves the account (a new
 * one too). A date already there stays: the first consent counts, and a
 * later sign-up form never opts anyone in or out.
 */
final readonly class RecordConsent
{
    public function handle(User $user, bool $wantsNews): User
    {
        $user->forceFill([
            'adult_confirmed_at' => $user->adult_confirmed_at ?? now(),
            // Once chosen, on or off, only the settings change it (SetMarketingConsent): a later form never opts back in.
            'marketing_opt_in_at' => $user->marketing_opt_in_at !== null || $user->marketing_opt_out_at !== null
                ? $user->marketing_opt_in_at
                : ($wantsNews ? now() : null),
        ])->save();

        return $user;
    }
}
