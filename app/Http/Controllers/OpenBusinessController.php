<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\SelectTenant;
use App\Http\Middleware\SetTenant;
use App\Http\Requests\OpenBusinessRequest;
use App\Models\User;
use App\Support\Auth\MailedLink;
use Illuminate\Http\RedirectResponse;

/**
 * A business's page from its emails (CHW-295): the verification emails'
 * and the fraud alerts' signed link (MailedLink::toBusiness), for the user
 * it was mailed to (OpenBusinessRequest), selects the business it names,
 * as the tenant switcher would, then opens the page. Someone who doesn't
 * work there (any more), or a business that no longer operates, gets a 404
 * and keeps the business they had selected. Who may see the page is still
 * the page's own rule.
 */
final class OpenBusinessController extends Controller
{
    public function __invoke(OpenBusinessRequest $request, int $business, string $page, SelectTenant $selectTenant): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        if (! $selectTenant->handle($user, SetTenant::businessChoice($business))) {
            abort(404);
        }

        $request->session()->put(SetTenant::SESSION_KEY, SetTenant::businessChoice($business));

        return to_route(MailedLink::BUSINESS_PAGES[$page]);
    }
}
