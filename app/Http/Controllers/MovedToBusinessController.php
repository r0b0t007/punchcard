<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The business app's pages before they moved under /business (CHW-151):
 * /dashboard is now /business, the cards and the onboarding wizard keep
 * their path under it. A permanent redirect, with the query string
 * (?location=), for bookmarks and links already sent.
 */
final class MovedToBusinessController extends Controller
{
    public function __invoke(Request $request, string $path): RedirectResponse
    {
        $moved = $path === 'dashboard' ? '/business' : "/business/{$path}";

        return redirect()->to(
            $request->getQueryString() === null ? $moved : $moved.'?'.$request->getQueryString(),
            301,
        );
    }
}
