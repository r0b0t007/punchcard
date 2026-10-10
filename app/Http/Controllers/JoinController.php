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
 * - GET /j/{slug} (throttled): the business's card. Viewing it changes
 *   nothing, so a glance never decides where a later sign-in lands.
 * - GET /j/{slug}/register and /j/{slug}/login (signed out): the page's
 *   "Continue with email" and "I already have an account": come back here
 *   after signing in (url.intended), unless a tap waits to be claimed,
 *   whose stamp comes first; then on to Fortify's page.
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

        return Inertia::render('join/show', [
            'slug' => $business->slug,
            ...$describe->handle($business, $customer instanceof User ? $customer : null),
        ]);
    }

    public function register(Request $request, string $slug, FindJoinableBusiness $find): RedirectResponse
    {
        $this->comeBack($request, $this->business($find, $slug));

        return to_route('register');
    }

    public function login(Request $request, string $slug, FindJoinableBusiness $find): RedirectResponse
    {
        $this->comeBack($request, $this->business($find, $slug));

        return to_route('login');
    }

    public function store(Request $request, string $slug, FindJoinableBusiness $find, JoinCard $joinCard): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $business = $this->business($find, $slug);
        $joinCard->handle($business, $customer);

        return to_route('join.show', $business->slug);
    }

    /**
     * Back to this join page after signing in, unless a tap waits to be
     * claimed: compared by path, as the tap may have set it on another host.
     */
    private function comeBack(Request $request, Business $business): void
    {
        $intended = $request->session()->get('url.intended');
        $claim = parse_url(route('taps.claim'), PHP_URL_PATH);

        if (! is_string($intended) || parse_url($intended, PHP_URL_PATH) !== $claim) {
            redirect()->setIntendedUrl(route('join.show', $business->slug));
        }
    }

    private function business(FindJoinableBusiness $find, string $slug): Business
    {
        return $find->handle($slug) ?? abort(404);
    }
}
