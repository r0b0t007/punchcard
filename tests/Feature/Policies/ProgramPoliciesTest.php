<?php

declare(strict_types=1);

use App\Actions\Tenancy\ResolveTenant;
use App\Enums\BusinessRole;
use App\Enums\PlatformRole;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CardBusiness;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\NfcTag;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

use function Filament\get_authorization_response;

/*
|--------------------------------------------------------------------------
| Program and customer-data policies (CHW-22)
|--------------------------------------------------------------------------
|
| Organization A is a franchise (HQ, franchisees A1 and A2, one shared card);
| B an independent café whose owner runs its card. A customer stamped only at
| A2: at a counter of the card anyone sees their progress; in the portal only
| HQ and A2's owner see them. The ledger and the tap log are never changed.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $people = fn (BusinessRole $role, Business $business): User => $this->tenants->member(User::factory()->create(), $business, $role);

    $this->hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->ownerA1 = $people(BusinessRole::Owner, $this->tenants->a1);
    $this->staffA1 = $people(BusinessRole::Staff, $this->tenants->a1);
    $this->ownerA2 = $people(BusinessRole::Owner, $this->tenants->a2);
    $this->ownerB1 = $people(BusinessRole::Owner, $this->tenants->b1);
    $this->staffB1 = $people(BusinessRole::Staff, $this->tenants->b1);

    // A customer who went only to A2.
    $this->enrollment = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);
    $this->eventAtA2 = $this->tenants->stamp($this->enrollment, $this->tenants->a2);
    $this->reward = $this->tenants->reward($this->enrollment);

    $this->as = function (User $user, ?string $choice = null): GateContract {
        app(ResolveTenant::class)->handle($user, $choice);

        return Gate::forUser($user);
    };
    $this->allowed = fn (User $user, string $ability, mixed $arguments): bool => ($this->as)($user)->allows($ability, $arguments);
});

it('lets the org admin acting for the organization run the card and its rules, never a franchisee', function (string $ability): void {
    $cardA = $this->tenants->cardA;

    expect(($this->allowed)($this->hq, $ability, $cardA))->toBeTrue()
        ->and(($this->allowed)($this->ownerA1, $ability, $cardA))->toBeFalse()
        ->and(($this->allowed)($this->staffA1, $ability, $cardA))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, $ability, $cardA))->toBeFalse()
        // An independent café's owner runs its own card, rules included.
        ->and(($this->allowed)($this->ownerB1, $ability, $this->tenants->cardB))->toBeTrue()
        ->and(($this->allowed)($this->staffB1, $ability, $this->tenants->cardB))->toBeFalse();
})->with(['update', 'updateRules']);

it('fixes a card\'s mode and keeps the card once customers hold it', function (string $ability): void {
    // The reasons are translated; the app's default locale is French.
    app()->setLocale('en');
    $unheld = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::factory()->for($this->tenants->orgA)->create());

    expect(($this->allowed)($this->hq, $ability, $this->tenants->cardA))->toBeFalse()
        ->and(($this->as)($this->hq)->inspect($ability, $this->tenants->cardA)->message())->toContain('Customers hold this card')
        ->and(($this->allowed)($this->hq, $ability, $unheld))->toBeTrue()
        ->and(($this->allowed)($this->ownerA1, $ability, $unheld))->toBeFalse()
        // Without rights, nothing about the card's holders.
        ->and((string) ($this->as)($this->ownerA1)->inspect($ability, $this->tenants->cardA)->message())->not->toContain('Customers hold')
        ->and((string) ($this->as)($this->ownerB1)->inspect($ability, $this->tenants->cardA)->message())->not->toContain('Customers hold');
})->with(['changeMode', 'delete']);

it('lets a business see the card it honours, and HQ inside it no more than that', function (): void {
    $cardA = $this->tenants->cardA;
    $this->tenants->member($this->hq, $this->tenants->a1, BusinessRole::Owner);

    expect(($this->allowed)($this->staffA1, 'view', $cardA))->toBeTrue()
        ->and(($this->allowed)($this->ownerA2, 'view', $cardA))->toBeTrue()
        ->and(($this->allowed)($this->ownerB1, 'view', $cardA))->toBeFalse()
        ->and(($this->as)($this->hq, 'business:'.$this->tenants->a1->id)->allows('create', [LoyaltyCard::class, $this->tenants->orgA]))->toBeFalse()
        ->and(($this->as)($this->hq, 'business:'.$this->tenants->a1->id)->allows('updateRules', $cardA))->toBeFalse()
        ->and(($this->as)($this->hq, 'org:'.$this->tenants->orgA->id)->allows('create', [LoyaltyCard::class, $this->tenants->orgA]))->toBeTrue();
});

it('lets only the org admin decide which businesses honour the card', function (): void {
    $participation = $this->context->bypass(fn (): CardBusiness => CardBusiness::query()->where('business_id', $this->tenants->a2->id)->firstOrFail());

    expect(($this->allowed)($this->hq, 'create', [CardBusiness::class, $this->tenants->cardA]))->toBeTrue()
        ->and(($this->allowed)($this->hq, 'delete', $participation))->toBeTrue()
        ->and(($this->allowed)($this->ownerA2, 'delete', $participation))->toBeFalse()
        ->and(($this->allowed)($this->ownerA1, 'create', [CardBusiness::class, $this->tenants->cardA]))->toBeFalse();
});

it('shows a customer\'s details only where they went, and to the org admin', function (string $what): void {
    $subject = $what === 'card' ? $this->enrollment : $this->reward;
    $class = $subject::class;

    expect(($this->allowed)($this->hq, 'view', $subject))->toBeTrue()
        ->and(($this->allowed)($this->ownerA2, 'view', $subject))->toBeTrue()
        ->and(($this->allowed)($this->ownerA1, 'view', $subject))->toBeFalse()
        ->and(($this->allowed)($this->staffA1, 'view', $subject))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, 'view', $subject))->toBeFalse()
        ->and(($this->allowed)($this->ownerA1, 'viewAny', $class))->toBeTrue()
        ->and(($this->allowed)($this->hq, 'viewAny', $class))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, 'viewAny', $class))->toBeFalse()
        ->and(($this->allowed)(User::factory()->create(), 'viewAny', $class))->toBeFalse();
})->with(['card', 'reward']);

it('shows HQ at its own site the whole program, as the tenant scope does', function (string $what): void {
    $subject = $what === 'card' ? $this->enrollment : $this->reward;
    $this->tenants->member($this->hq, $this->tenants->a1, BusinessRole::Owner);

    expect(($this->as)($this->hq, 'business:'.$this->tenants->a1->id)->allows('view', $subject))->toBeTrue();
})->with(['card', 'reward']);

it('shows a business the rewards redeemed there, for its "redeemed here" report', function (): void {
    $this->context->bypass(fn () => $this->reward->forceFill([
        'status' => 'redeemed', 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id,
        'redeemed_location_id' => $this->tenants->locationOf($this->tenants->a1)->id,
    ])->save());

    expect(($this->allowed)($this->ownerA1, 'view', $this->reward->fresh()))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, 'view', $this->reward->fresh()))->toBeFalse()
        ->and(($this->allowed)($this->ownerA1, 'view', $this->enrollment))->toBeFalse();
});

it('never counts a system stamp as a visit', function (): void {
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['source' => StampSource::Bonus]);

    expect(($this->allowed)($this->ownerA1, 'view', $this->enrollment))->toBeFalse();

    $this->tenants->stamp($this->enrollment, $this->tenants->a1);

    // A visit at A1 shows the customer to A1's owner, never to its staff.
    expect(($this->allowed)($this->ownerA1, 'view', $this->enrollment))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, 'view', $this->enrollment))->toBeFalse();
});

it('shows a business\'s stamps to its owner or org admin only', function (): void {
    expect(($this->allowed)($this->ownerA2, 'viewAny', [StampEvent::class, $this->tenants->a2]))->toBeTrue()
        ->and(($this->allowed)($this->hq, 'viewAny', [StampEvent::class, $this->tenants->a2]))->toBeTrue()
        ->and(($this->allowed)($this->ownerA1, 'viewAny', [StampEvent::class, $this->tenants->a2]))->toBeFalse()
        ->and(($this->allowed)($this->staffA1, 'viewAny', [StampEvent::class, $this->tenants->a1]))->toBeFalse()
        ->and(($this->allowed)($this->ownerA2, 'view', $this->eventAtA2))->toBeTrue()
        ->and(($this->allowed)($this->ownerA1, 'view', $this->eventAtA2))->toBeFalse()
        ->and(($this->allowed)($this->hq, 'view', $this->eventAtA2))->toBeTrue();
});

it('lets anyone working there stamp by hand or take stamps back, as AddStamps decides', function (string $ability): void {
    $site = $this->tenants->locationOf($this->tenants->a1);
    $terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create());
    $terraceStaff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $terraceStaff->id)->update(['location_id' => $terrace->id]));
    // A customer A1 has seen; the one who only went to A2 is not A1's to stamp.
    $seen = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);
    $this->tenants->stamp($seen, $this->tenants->a1);
    $class = StampEvent::class;

    expect(($this->allowed)($this->staffA1, $ability, [$class, $site, $seen]))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, $ability, [$class, $site, $this->enrollment]))->toBeFalse()
        ->and(($this->allowed)($this->ownerA1, $ability, [$class, $terrace, $seen]))->toBeTrue()
        ->and(($this->allowed)($terraceStaff, $ability, [$class, $terrace, $seen]))->toBeTrue()
        ->and(($this->allowed)($terraceStaff, $ability, [$class, $site, $seen]))->toBeFalse()
        ->and(($this->allowed)($this->ownerA2, $ability, [$class, $site, $seen]))->toBeFalse()
        ->and(($this->allowed)($this->hq, $ability, [$class, $site, $seen]))->toBeFalse();

    // An org admin with a site-limited staff row stamps anywhere, as AddStamps lets them.
    $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $terraceStaff->id)->delete());
    $this->tenants->admin($terraceStaff, $this->tenants->orgA);
    $this->tenants->member($terraceStaff, $this->tenants->a1);
    $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $terraceStaff->id)->update(['location_id' => $terrace->id]));

    expect(($this->as)($terraceStaff, 'business:'.$this->tenants->a1->id)->allows($ability, [$class, $site, $seen]))->toBeTrue();
})->with(['createManual', 'correct']);

it('gives a manual stamp only on a card honoured there, a correction also where it no longer is', function (): void {
    // HQ at its own site sees every customer of the program, A2's own card included.
    $this->tenants->member($this->hq, $this->tenants->a1, BusinessRole::Owner);
    $a2Only = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create();
        $card->businesses()->attach($this->tenants->a2->id);

        return $card;
    });
    $member = $this->tenants->enroll(User::factory()->create(), $a2Only);
    $gate = ($this->as)($this->hq, 'business:'.$this->tenants->a1->id);
    $site = $this->tenants->locationOf($this->tenants->a1);

    expect($gate->allows('createManual', [StampEvent::class, $site, $member]))->toBeFalse()
        ->and($gate->allows('correct', [StampEvent::class, $site, $member]))->toBeTrue();
});

it('keeps the tap log and the tags to the platform admin, who never deletes a tag or changes the ledger', function (): void {
    // Platform data: the policies never read the row, so unsaved models will do.
    $tap = new Tap;
    $tag = new NfcTag;

    foreach ([$this->hq, $this->ownerA1, $this->staffA1] as $user) {
        expect(($this->allowed)($user, 'view', $tap))->toBeFalse()
            ->and(($this->allowed)($user, 'view', $tag))->toBeFalse()
            ->and(($this->allowed)($user, 'delete', $tag))->toBeFalse();
    }

    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setServingStatus();
    $unheld = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::factory()->for($this->tenants->orgA)->create());
    // As Filament asks: an action whose policy has no method is allowed unless the Gate says no.
    $filament = fn (string $action, mixed $model): bool => get_authorization_response($action, $model)->allowed();

    try {
        expect($filament('view', $tap))->toBeTrue()
            ->and($filament('update', $tag))->toBeTrue()
            ->and($filament('updateRules', $this->tenants->cardA))->toBeTrue()
            ->and($filament('delete', $unheld))->toBeTrue()
            ->and($filament('delete', $tag))->toBeFalse()
            ->and($filament('deleteAny', NfcTag::class))->toBeFalse()
            ->and($filament('forceDelete', $tag))->toBeFalse()
            ->and($filament('update', $tap))->toBeFalse()
            ->and($filament('update', $this->eventAtA2))->toBeFalse()
            ->and($filament('delete', $this->eventAtA2))->toBeFalse()
            ->and($filament('deleteAny', StampEvent::class))->toBeFalse()
            ->and($filament('delete', $this->tenants->cardA))->toBeFalse()
            ->and($filament('changeMode', $this->tenants->cardA))->toBeFalse();
    } finally {
        Filament::setServingStatus(false);
    }
});
