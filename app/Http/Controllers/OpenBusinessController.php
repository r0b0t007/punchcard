<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\SelectBusiness;
use App\Http\Middleware\SetTenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A business's page from its emails (CHW-295): the verification emails'
 * and the fraud alerts' signed link (SelectBusiness::link) selects the
 * business it names, as the tenant switcher would, then opens the page.
 * Anyone but the user it was mailed to, someone who doesn't work there
 * (any more), or a business that no longer operates gets a 404 and keeps
 * the business they had selected. Who may see the page is still the
 * page's own rule.
 */
final class OpenBusinessController extends Controller
{
    public function __invoke(Request $request, int $business, string $page, SelectBusiness $selectBusiness): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        if ($request->integer('for') !== $user->id || ! $selectBusiness->handle($user, $business)) {
            abort(404);
        }

        $request->session()->put(SetTenant::SESSION_KEY, 'business:'.$business);

        return to_route(SelectBusiness::PAGES[$page]);
    }
}
