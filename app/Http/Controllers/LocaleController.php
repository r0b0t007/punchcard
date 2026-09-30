<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Locale\UpdateLocale;
use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;

class LocaleController extends Controller
{
    /**
     * Switch the UI language for the current visitor.
     */
    public function update(UpdateLocaleRequest $request, UpdateLocale $updateLocale): RedirectResponse
    {
        $locale = $request->string('locale')->toString();

        $updateLocale->handle($request->user(), $locale);

        // The cookie covers guests and signed-out sessions on this device.
        return back()->withCookie(cookie()->forever('locale', $locale));
    }
}
