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
 * forgets an opt-out; turning it off dates the opt-out, a first "no" from
 * someone who never chose included (a later form then respects it). The
 * same state again changes nothing, so the date stays the first of the run.
 * Every change is also logged (ConsentEvent): the columns are the state,
 * the log its history.
 *
 * - Turning it on here needs a confirmed email. A sign-up records the
 *   person's choice before that: the page then shows it on with the prompt
 *   to confirm, and nothing is sent until then (User::receivesNews).
 *   Turning it off never needs one.
 * - The user is locked: an account being deleted (DeleteAccount locks it
 *   too) never gets consent back.
 */
final readonly class SetMarketingConsent
{
    public function handle(User $user, bool $wantsNews): void
    {
        DB::transaction(function () use ($user, $wantsNews): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $locked instanceof User || $locked->isAnonymised()) {
                return;
            }

            // Already so: on since a date, or a "no" already recorded.
            if ($wantsNews ? $locked->marketing_opt_in_at !== null : $locked->marketing_opt_out_at !== null) {
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
