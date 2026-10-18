<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Models\CardEnrollment;
use App\Models\ConsentEvent;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Places followed, on the Privacy page (CHW-58, C12, review 7a)
|--------------------------------------------------------------------------
|
| Every program the customer holds a card in, one row each: following or
| not, since when, and the switch. The same Follow as on the card's page
| (C6, SetFollow): a change on one shows on the other, and each is logged
| with where it came from. A closed program can be left, never joined. Only
| the customer's own cards: another's is a 404.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-18 10:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create();
    $this->atA = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->atB = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->page = fn (?User $as = null) => $this->actingAs($as ?? $this->customer)->get(route('privacy.edit'));
    $this->follow = fn (int $enrollmentId, bool $follow, ?User $as = null) => $this->actingAs($as ?? $this->customer)
        ->patch(route('privacy.follow', $enrollmentId), ['follow' => $follow]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('lists one row per program the customer holds a card in, none followed yet', function (): void {
    $row = fn (CardEnrollment $enrollment, Organization $organization): array => [
        'enrollmentId' => $enrollment->id, 'organization' => $organization->name, 'logoUrl' => null,
        'following' => false, 'changedAt' => null, 'canFollow' => true,
    ];

    ($this->page)()->assertOk()->assertInertia(fn (Assert $page): Assert => $page->where('programs', fn ($programs): bool => collect($programs)
        ->sortBy('enrollmentId')->values()->all() === [$row($this->atA, $this->tenants->orgA), $row($this->atB, $this->tenants->orgB)]));
});

it('follows and leaves from here, as on the card\'s page, each change dated and logged', function (): void {
    ($this->follow)($this->atA->id, true)->assertRedirect(route('privacy.edit'));

    ($this->page)()->assertInertia(fn (Assert $page): Assert => $page->where('programs', fn ($programs): bool => collect($programs)
        ->firstWhere('enrollmentId', $this->atA->id)['following'] === true && collect($programs)->firstWhere('enrollmentId', $this->atA->id)['changedAt'] === '2026-10-18T10:00:00+00:00'));

    $this->actingAs($this->customer)->get(route('app.cards.show', $this->atA->id))
        ->assertInertia(fn (Assert $page): Assert => $page->where('following', true));

    Carbon::setTestNow('2026-10-20 09:00:00');
    $this->actingAs($this->customer)->patch(route('app.cards.follow', $this->atA->id), ['follow' => false]);

    ($this->page)()->assertInertia(fn (Assert $page): Assert => $page->where('programs', fn ($programs): bool => collect($programs)
        ->firstWhere('enrollmentId', $this->atA->id) === ['enrollmentId' => $this->atA->id, 'organization' => $this->tenants->orgA->name, 'logoUrl' => null,
            'following' => false, 'changedAt' => '2026-10-20T09:00:00+00:00', 'canFollow' => true]));

    expect(ConsentEvent::query()->where('user_id', $this->customer->id)->orderBy('id')->pluck('source')->all())->toBe(['settings', 'card']);
});

it('lets a closed program be left, never joined', function (): void {
    ($this->follow)($this->atB->id, true);
    $this->context->bypass(fn () => $this->tenants->orgB->forceFill(['archived_at' => now()])->save());

    ($this->page)()->assertInertia(fn (Assert $page): Assert => $page->where('programs', fn ($programs): bool => collect($programs)
        ->firstWhere('enrollmentId', $this->atB->id)['canFollow'] === false));

    ($this->follow)($this->atB->id, false)->assertSessionHasNoErrors();
    ($this->follow)($this->atB->id, true)->assertSessionHasErrors('follow');
});

it('follows only from a confirmed email, and leaves without one', function (): void {
    $unconfirmed = User::factory()->unverified()->create();
    $theirs = $this->tenants->enroll($unconfirmed, $this->tenants->cardA);

    ($this->follow)($theirs->id, true, $unconfirmed)->assertSessionHasErrors('follow');
    ($this->follow)($theirs->id, false, $unconfirmed)->assertSessionHasNoErrors();
});

it('never touches another customer\'s card', function (): void {
    $other = User::factory()->create();
    $theirs = $this->tenants->enroll($other, $this->tenants->cardA);

    ($this->follow)($theirs->id, true)->assertNotFound();
    ($this->page)()->assertInertia(fn (Assert $page): Assert => $page->where('programs', fn ($programs): bool => collect($programs)
        ->pluck('enrollmentId')->doesntContain($theirs->id)));
});

it('shows an owner their own places in other organizations', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $card = $this->tenants->enroll($owner, $this->tenants->cardB);

    ($this->page)($owner)->assertInertia(fn (Assert $page): Assert => $page->where('programs.0.enrollmentId', $card->id));
});

it('shows an empty list to someone with no card yet', function (): void {
    ($this->page)(User::factory()->create())->assertInertia(fn (Assert $page): Assert => $page->where('programs', []));
});

it('shows a program once, however many of its cards the customer holds', function (): void {
    $second = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create(['name' => 'A second card']);
        $card->businesses()->attach([$this->tenants->a1->id]);

        return $card;
    });
    $this->tenants->enroll($this->customer, $second);

    ($this->page)()->assertInertia(fn (Assert $page): Assert => $page->has('programs', 2));
});
