<?php

declare(strict_types=1);

namespace App\Actions\Locale;

use App\Models\User;

final class UpdateLocale
{
    /**
     * Remember a signed-in user's language on their account. Guests only get
     * the cookie, which the controller sets.
     */
    public function handle(?User $user, string $locale): void
    {
        $user?->update(['locale' => $locale]);
    }
}
