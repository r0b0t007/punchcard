<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Override;

/**
 * Laravel's session start, except that a tap's URL is never kept as the page
 * to go back to (CHW-149): /t carries the raw SUN parameters (e and c), which
 * never go into the session (sun-nfc-verification), and sessions are stored
 * unencrypted. The previous page stays what it was. Every other page is kept
 * as Laravel keeps it.
 */
final class StartSessionWithoutTapUrls extends StartSession
{
    /** @param  Session  $session */
    #[Override]
    protected function storeCurrentUrl(Request $request, $session): void
    {
        if ($request->routeIs('taps.receive')) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
