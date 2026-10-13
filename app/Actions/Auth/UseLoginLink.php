<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spends an email sign-in link (CHW-23), once: the row is locked, then
 * marked used, so two taps on it never both sign in. Null when it was used
 * or is out of date, or when its account may no longer use links (turned on
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
        $link = DB::transaction(function () use ($linkId): ?LoginLink {
            $link = LoginLink::query()->lockForUpdate()->find($linkId);

            if (! $link instanceof LoginLink || ! $link->isUsable()) {
                return null;
            }

            $link->forceFill(['used_at' => now()])->save();

            return $link;
        });

        if (! $link instanceof LoginLink) {
            return null;
        }

        $user = User::query()->where('email', $link->email)->first();

        if ($user instanceof User && ! $this->maySignInByLink->handle($user)) {
            return null;
        }

        return ['email' => $link->email, 'user' => $user];
    }
}
