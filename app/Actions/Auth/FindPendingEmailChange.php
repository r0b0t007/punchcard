<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

/**
 * The account a new-address link may still switch (CHW-23,
 * RequestEmailChange): the one waiting for exactly the address the link's
 * hash names, in date and not deleted since. Null when the link is out of
 * date, replaced by a later request, cancelled, or the account anonymised.
 * Its page and its button (ApplyEmailChange, which locks the row) both ask.
 */
final readonly class FindPendingEmailChange
{
    public function handle(int $userId, string $hash, bool $lock = false): ?User
    {
        $user = User::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($userId);
        $pending = $user instanceof User && ! $user->isAnonymised() ? $user->pendingEmail() : null;

        return $pending !== null && hash_equals(sha1($pending), $hash) ? $user : null;
    }
}
