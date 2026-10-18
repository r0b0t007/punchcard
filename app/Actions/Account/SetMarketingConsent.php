<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\ConsentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * punchcard's news by email, on or off, from the settings (CHW-58, C12
 * Privacy). Each state keeps its date: turning it on dates the consent and
 * forgets an opt-out; turning it off dates the opt-out. The same state again
 * changes nothing, so the date stays the first of the run. Every change is
 * also logged (ConsentEvent): the columns are the state, the log its history.
 *
 * - Turning it on here needs a confirmed email, so the switch never shows
 *   on for an inbox nobody proved (a sign-up records the person's choice
 *   before that; news only reaches confirmed inboxes, User::receivesNews).
 *   Turning it off never does.
 * - The user is locked: an account being deleted (DeleteAccount locks it
 *   too) never gets consent back.
 */
final readonly class SetMarketingConsent
{
    public function handle(User $user, bool $wantsNews): void
    {
        DB::transaction(function () use ($user, $wantsNews): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $locked instanceof User || $locked->isAnonymised() || $wantsNews === ($locked->marketing_opt_in_at !== null)) {
                return;
            }

            if ($wantsNews && ! $locked->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['marketing' => __('Confirm your email first to get news.')]);
            }

            $locked->forceFill($wantsNews
                ? ['marketing_opt_in_at' => now(), 'marketing_opt_out_at' => null]
                : ['marketing_opt_in_at' => null, 'marketing_opt_out_at' => now()])->save();
            ConsentEvent::record($locked->id, ConsentEvent::KIND_PUNCHCARD_NEWS, $wantsNews, ConsentEvent::SOURCE_SETTINGS);
        });
    }
}
