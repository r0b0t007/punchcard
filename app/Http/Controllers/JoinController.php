<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cards\DescribeJoinPage;
use App\Actions\Cards\FindJoinableBusiness;
use App\Actions\Cards\JoinCard;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The join page (CHW-31), the QR stand's link. Never cached (NeverCache).
 *
 * - GET /j/{slug} (throttled per client address): the business's card.
 *   Signed out, the visitor signs in or registers and comes back here
 *   (url.intended), unless a tap already waits to be claimed there.
 * - POST /j/{slug} (signed in, throttled per customer): add the card to
 *   theirs (JoinCard), then back to the page.
 *
 * An unknown, suspended or closed business is a 404 (FindJoinableBusiness).
 */
final class JoinController extends Controller
{
    public function show(Request $request, string $slug, FindJoinableBusiness $find, DescribeJoinPage $describe): Response
    {
        $business = $this->business($find, $slug);
        $customer = $request->user();

        // Back here after signing in, replacing any page left behind, except a tap waiting to be claimed: its stamp comes first.
        if (! $customer instanceof User && $request->session()->get('url.intended') !== route('taps.claim')) {
            redirect()->setIntendedUrl(route('join.show', $business->slug));
        }

        return Inertia::render('join/show', [
            'slug' => $business->slug,
            ...$describe->handle($business, $customer instanceof User ? $customer : null),
        ]);
    }

    public function store(Request $request, string $slug, FindJoinableBusiness $find, JoinCard $joinCard): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $business = $this->business($find, $slug);
        $joinCard->handle($business, $customer);

        return to_route('join.show', $business->slug);
    }

    private function business(FindJoinableBusiness $find, string $slug): Business
    {
        return $find->handle($slug) ?? abort(404);
    }
}
