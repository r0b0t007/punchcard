<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cards\ListCards;
use App\Actions\Cards\SaveCard;
use App\Actions\Cards\SetCardActive;
use App\Actions\Cards\UpdateCardBanner;
use App\Http\Requests\Cards\CardBannerRequest;
use App\Http\Requests\Cards\CardRequest;
use App\Http\Requests\Cards\CardStatusRequest;
use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's cards (CHW-32): B7, and the card builder's saves.
 *
 * - GET /cards: the cards (ListCards). Whoever runs the program
 *   (LoyaltyCardPolicy::create) manages them; a franchisee's owner
 *   (BusinessPolicy::update) sees its business's cards, read only; staff
 *   get a 403.
 * - POST /cards, PUT /cards/{card}: a new card (paused) or the changes
 *   (SaveCard, CardRequest).
 * - PATCH /cards/{card}/status: publish or pause (SetCardActive).
 * - POST /cards/{card}/banner: the card's banner (UpdateCardBanner).
 *
 * {card} resolves inside the tenant: another organization's card is a 404.
 */
final class CardController extends Controller
{
    public function index(TenantContext $context, ListCards $listCards): Response
    {
        $organization = $context->organizationId() === null ? null : Organization::query()->find($context->organizationId());
        $business = $context->businessId() === null ? null : Business::query()->find($context->businessId());
        $managesProgram = $organization instanceof Organization && Gate::allows('create', [LoyaltyCard::class, $organization]);

        if (! $managesProgram && (! $business instanceof Business || ! Gate::allows('update', $business))) {
            abort(403);
        }

        return Inertia::render('cards/index', [
            'cards' => $listCards->handle($managesProgram),
            'canManage' => $managesProgram,
        ]);
    }

    public function store(CardRequest $request, SaveCard $saveCard): RedirectResponse
    {
        $organization = $request->organization();
        assert($organization instanceof Organization);

        $saveCard->handle($organization, null, $request->validated());

        return to_route('cards.index');
    }

    public function update(CardRequest $request, LoyaltyCard $card, SaveCard $saveCard): RedirectResponse
    {
        $organization = $request->organization();
        assert($organization instanceof Organization);

        $saveCard->handle($organization, $card, $request->validated());

        return to_route('cards.index');
    }

    public function status(CardStatusRequest $request, LoyaltyCard $card, SetCardActive $setCardActive): RedirectResponse
    {
        $setCardActive->handle($card, $request->boolean('active'));

        return to_route('cards.index');
    }

    public function banner(CardBannerRequest $request, LoyaltyCard $card, UpdateCardBanner $updateCardBanner): RedirectResponse
    {
        $banner = $request->file('banner');
        assert($banner instanceof UploadedFile);

        $updateCardBanner->handle($card, $banner);

        return back();
    }
}
