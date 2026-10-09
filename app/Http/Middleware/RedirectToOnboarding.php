<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Onboarding\FindUnfinishedBusiness;
use App\Models\Business;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an owner whose business is not set up yet from the dashboard into
 * the onboarding wizard (CHW-31): where a business sign-up and email
 * verification land. Customers and set-up owners go through.
 */
final readonly class RedirectToOnboarding
{
    public function __construct(private FindUnfinishedBusiness $findUnfinishedBusiness) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->findUnfinishedBusiness->handle($user) instanceof Business) {
            return to_route('onboarding.show');
        }

        return $next($request);
    }
}
