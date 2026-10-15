<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Auth\PasskeyOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Not now" on the passkey offered after an email-link sign-in (CHW-23,
 * PasskeyOffer): not offered again in this session. Adding the passkey
 * ends the offer by itself.
 */
final class PasskeyOfferController extends Controller
{
    public function dismiss(Request $request): RedirectResponse
    {
        PasskeyOffer::dismiss($request->session());

        return back();
    }
}
