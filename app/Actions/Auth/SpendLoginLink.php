<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LoginLink;

/**
 * Spends an email link, once and only for its purpose (CHW-23): one UPDATE
 * marks it used only if it is unused, in date and for that purpose, so of
 * two taps on it only one counts, on any database, with no lock held. Given
 * an address, only a link mailed to it is spent (anyone else leaves it to
 * its owner). Its address, or null when it can't be spent.
 */
final readonly class SpendLoginLink
{
    public function handle(int $id, string $purpose, ?string $email = null): ?string
    {
        $spent = LoginLink::query()
            ->whereKey($id)
            ->usable($purpose)
            ->when($email !== null, fn ($query) => $query->where('email', $email))
            ->update(['used_at' => now()]);

        $address = $spent === 1 ? LoginLink::query()->whereKey($id)->value('email') : null;

        return is_string($address) ? $address : null;
    }
}
