<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * punchcard's news by email, on or off, from the settings (CHW-58, C12
 * Privacy). Each state keeps its date: turning it on dates the consent and
 * forgets an opt-out; turning it off dates the opt-out. The same state again
 * changes nothing, so the date stays the first of the run.
 *
 * - Consent needs a verified email: whoever gives it owns the inbox the
 *   news goes to. Turning it off never does.
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
        });
    }
}
