<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LoginLink;
use App\Models\User;

/**
 * Spends a "confirm it's you" link for the account it was mailed to (CHW-23),
 * if it may confirm so: anyone else's attempt leaves it to its owner, and a
 * sign-in link never counts. Whether it confirmed; the caller then marks
 * the session confirmed and has checked the URL's signature.
 */
final readonly class ConfirmByLink
{
    public function __construct(
        private MayConfirmByEmail $mayConfirmByEmail,
        private SpendLoginLink $spendLoginLink,
    ) {}

    public function handle(User $user, int $linkId): bool
    {
        return $this->mayConfirmByEmail->handle($user)
            && $this->spendLoginLink->handle($linkId, LoginLink::CONFIRM, $user->email) !== null;
    }
}
