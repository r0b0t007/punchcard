<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Saves the profile's name and birthday (C12, CHW-46), from the validated
 * form. The birthday changes only when the form sent it: a save without the
 * two fields (a page from before them) keeps it; both sent empty remove it.
 * When it was given is kept with it (CHW-370): a first birthday, or another
 * date, is given now and counts for a bonus 30 days on; the same date saved
 * again keeps its moment, and a removed one takes it along.
 * The address is not saved here: a new one waits for its inbox
 * (RequestEmailChange).
 */
final readonly class UpdateProfile
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(User $user, array $validated): void
    {
        $user->fill(Arr::only($validated, ['name', 'birthday_day', 'birthday_month']));

        if ($user->isDirty(['birthday_day', 'birthday_month'])) {
            $user->forceFill(['birthday_set_at' => $user->birthday_month === null ? null : now()]);
        }

        $user->save();
    }
}
