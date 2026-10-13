<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LoginLink;
use App\Models\User;

/**
 * Spends an email sign-in link (CHW-23), once: one UPDATE marks it used
 * only if it is unused and in date, so of two taps on it only one ever
 * signs in, on any database, with no lock held. Null when it was used or
 * is out of date, or when its account may no longer use links (turned on
 * two-factor since, MaySignInByLink). Else its address, and the account
 * there if there is one (none: a new customer, who finishes signing up).
 * The caller has checked the URL's signature.
 */
final readonly class UseLoginLink
{
    public function __construct(private MaySignInByLink $maySignInByLink) {}

    /** @return array{email: string, user: User|null}|null */
    public function handle(int $linkId): ?array
    {
        $spent = LoginLink::query()
            ->whereKey($linkId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        $email = $spent === 1 ? LoginLink::query()->whereKey($linkId)->value('email') : null;

        if (! is_string($email)) {
            return null;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User && ! $this->maySignInByLink->handle($user)) {
            return null;
        }

        return ['email' => $email, 'user' => $user];
    }
}
