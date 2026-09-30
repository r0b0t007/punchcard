<?php

declare(strict_types=1);

namespace App\Actions\Locale;

use App\Models\User;
use App\Support\Locales;
use Illuminate\Http\Request;

/**
 * Pick the UI locale for a request: the signed-in user's choice, then a guest's
 * switcher cookie, then the browser's Accept-Language, then the default (fr).
 */
final class ResolveLocale
{
    public function handle(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User && Locales::isSupported($user->locale)) {
            return $user->locale;
        }

        $cookie = $request->cookie('locale');

        if (is_string($cookie) && Locales::isSupported($cookie)) {
            return $cookie;
        }

        // getLanguages() is sorted by quality and returns tags like "en_US".
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok($language, '_-') ?: '');

            if (Locales::isSupported($primary)) {
                return $primary;
            }
        }

        return Locales::default();
    }
}
