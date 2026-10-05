<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Taps\TapSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every web session its tap claim token on its first request
 * (TapSession, CHW-142), so any later session payload carries it: a request
 * writing back an older payload can't lose the token, and so can't lose the
 * signed-out taps waiting under it.
 */
final class GiveSessionTapToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        TapSession::ensureToken($request->session());
        $response = $next($request);

        // Again after: a session ended during the request (logout) is saved with a new token too.
        TapSession::ensureToken($request->session());

        return $response;
    }
}
