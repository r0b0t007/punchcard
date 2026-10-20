<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Account\RecordActivity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in request is activity (CHW-58, RecordActivity). Everything the
 * request notes, a stamp or a redemption too, is written once the response
 * is sent: no response waits on the user row, and signing in counts. With the
 * user the guard already has: at most one change to the row a day.
 */
final readonly class RecordLastActive
{
    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->recordActivity->hold();

        return $next($request);
    }

    public function terminate(Request $request): void
    {
        $user = $request->user();

        $this->recordActivity->release($user instanceof User ? $user : null, now());
    }
}
