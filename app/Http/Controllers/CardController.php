<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cards\DescribeCardBuilder;
use App\Actions\Cards\DescribeCardSaved;
use App\Actions\Cards\ListCards;
use App\Actions\Cards\SaveCard;
use App\Actions\Cards\SetCardActive;
use App\Actions\Cards\UpdateCardBanner;
use App\Http\Requests\Cards\CardBannerRequest;
use App\Http\Requests\Cards\CardRequest;
use App\Http\Requests\Cards\CardStatusRequest;
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
 * - GET /cards: the cards (ListCards, LoyaltyCardPolicy::viewAny). Whoever
 *   runs the program (LoyaltyCardPolicy::create) manages them; a
 *   franchisee's owner sees its business's cards, read only; staff get a
 *   403.
 * - GET /cards/create, /cards/{card}/edit: the card builder (B6,
 *   DescribeCardBuilder), for whoever runs the program.
 * - POST /cards, PUT /cards/{card}: a new card (paused, unless published)
 *   or the changes (SaveCard, CardRequest), with a warning when only pure
 *   white or black text reads on the card's colour (DescribeCardSaved).
 * - PATCH /cards/{card}/status: publish or pause (SetCardActive).
 * - POST /cards/{card}/banner: the card's banner (UpdateCardBanner).
 *
 * {card} resolves inside the tenant: another organization's card is a 404.
 */
final class CardController extends Controller
{
    public function index(TenantContext $context, ListCards $listCards): Response
    {
        Gate::authorize('viewAny', LoyaltyCard::class);

        $organization = $context->organization();
        $managesProgram = $organization instanceof Organization && Gate::allows('create', [LoyaltyCard::class, $organization]);

        return Inertia::render('cards/index', [
            'cards' => $listCards->handle($managesProgram),
            'canManage' => $managesProgram,
        ]);
    }

    public function create(TenantContext $context, DescribeCardBuilder $describeCardBuilder): Response
    {
        $organization = $context->organization();
        abort_unless($organization instanceof Organization, 403);
        Gate::authorize('create', [LoyaltyCard::class, $organization]);

        return Inertia::render('cards/edit', $describeCardBuilder->handle($organization, null));
    }

    public function edit(LoyaltyCard $card, DescribeCardBuilder $describeCardBuilder): Response
    {
        Gate::authorize('update', $card);

        return Inertia::render('cards/edit', $describeCardBuilder->handle(Organization::query()->whereKey($card->organization_id)->firstOrFail(), $card));
    }

    public function store(CardRequest $request, SaveCard $saveCard, DescribeCardSaved $describeCardSaved): RedirectResponse
    {
        $organization = $request->organization();
        assert($organization instanceof Organization);

        $card = $saveCard->handle($organization, null, $request->validated(), $request->boolean('publish'));
        Inertia::flash('toast', $describeCardSaved->handle($organization, $card, $request->boolean('publish')));

        return to_route('cards.index');
    }

    public function update(CardRequest $request, LoyaltyCard $card, SaveCard $saveCard, DescribeCardSaved $describeCardSaved): RedirectResponse
    {
        $organization = $request->organization();
        assert($organization instanceof Organization);

        $saved = $saveCard->handle($organization, $card, $request->validated(), $request->boolean('publish'));
        Inertia::flash('toast', $describeCardSaved->handle($organization, $saved, $request->boolean('publish')));

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
