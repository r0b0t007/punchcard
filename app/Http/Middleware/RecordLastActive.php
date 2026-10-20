<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Account\RecordActivity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in request is activity (CHW-58, RecordActivity). Read once the
 * response is sent, so signing in counts too and no response waits for it,
 * with the user the guard already has: at most one write a day.
 */
final readonly class RecordLastActive
{
    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->recordActivity->handle($user, now());
        }
    }
}
