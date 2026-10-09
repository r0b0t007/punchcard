<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Onboarding\FindUnfinishedBusiness;
use App\Actions\Tenancy\ResolveTenant;
use App\Models\Business;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The onboarding wizard's tenant (alias `onboarding`, CHW-31): the business
 * the user is setting up (FindUnfinishedBusiness), entered for the wizard's
 * own requests, so its owner works in it even with other businesses
 * elsewhere. The tenant switcher's choice in the session is left as it is.
 * No wizard route names a business: this is the only one it can reach. With
 * none to finish, the wizard starts one; the tenant is resolved as anywhere
 * else.
 */
final readonly class ResolveOnboardingBusiness
{
    public const string ATTRIBUTE = 'onboarding.business';

    public function __construct(
        private FindUnfinishedBusiness $findUnfinishedBusiness,
        private ResolveTenant $resolveTenant,
    ) {}

    /** The business the wizard works on, if any. */
    public static function of(Request $request): ?Business
    {
        $business = $request->attributes->get(self::ATTRIBUTE);

        return $business instanceof Business ? $business : null;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $business = $this->findUnfinishedBusiness->handle($user);

        $choice = $business instanceof Business ? 'business:'.$business->id : $request->session()->get(SetTenant::SESSION_KEY);
        $this->resolveTenant->handle($user, is_string($choice) ? $choice : null);
        $request->attributes->set(self::ATTRIBUTE, $business);

        return $next($request);
    }
}
