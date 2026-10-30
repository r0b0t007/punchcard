<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\SelectTenant;
use App\Http\Middleware\SetTenant;
use App\Http\Requests\SwitchTenantRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * The tenant switcher (CHW-147, design 10a and 10d): works in the business
 * or network chosen (SelectTenant), then opens its dashboard, never the
 * page it was chosen from, which may be another business's. One the user
 * may not work in (any more) is a 404 and switches nothing.
 */
final class TenantController extends Controller
{
    public function update(SwitchTenantRequest $request, SelectTenant $selectTenant): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        if (! $selectTenant->handle($user, $request->choice())) {
            abort(404);
        }

        $request->session()->put(SetTenant::SESSION_KEY, $request->choice());

        return to_route('dashboard');
    }
}
