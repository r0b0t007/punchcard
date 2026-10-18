<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\ConsentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dates what a new account said on signing up (CHW-23, CNDP, ConsentRules):
 * 16 or older, and news by email only when ticked. Saves the account (a new
 * one too). A date already there stays: the first consent counts, and a
 * later sign-up form never opts anyone in or out once they chose. An opt-in
 * is logged (ConsentEvent). It is recorded even before the email is
 * confirmed: it is the person's choice; news only ever reaches a confirmed
 * inbox (User::receivesNews).
 */
final readonly class RecordConsent
{
    public function handle(User $user, bool $wantsNews): User
    {
        return DB::transaction(function () use ($user, $wantsNews): User {
            if ($user->exists) {
                // Read again under its lock: a settings change made meanwhile is never undone.
                $current = User::query()->whereKey($user->id)->lockForUpdate()->first();

                if (! $current instanceof User) {
                    // Deleted meanwhile: nobody's consent to record.
                    return $user;
                }

                $user->setRawAttributes($current->getAttributes(), sync: true);
            }

            // Once chosen, on or off, only the settings change it (SetMarketingConsent): a later form never opts back in.
            $optsIn = $wantsNews && $user->marketing_opt_in_at === null && $user->marketing_opt_out_at === null;

            $user->forceFill(['adult_confirmed_at' => $user->adult_confirmed_at ?? now()]
                + ($optsIn ? ['marketing_opt_in_at' => now()] : []))->save();

            if ($optsIn) {
                ConsentEvent::record($user->id, ConsentEvent::KIND_PUNCHCARD_NEWS, true, ConsentEvent::SOURCE_SIGN_UP);
            }

            return $user;
        });
    }
}
