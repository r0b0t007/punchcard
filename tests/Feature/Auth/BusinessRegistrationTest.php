<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Business sign-up (CHW-31, spec B1)
|--------------------------------------------------------------------------
|
| An owner signs up with their account and their business's name in one
| form: the account, an independent organization and a pending business
| are created together or not at all. The onboarding wizard then sets the
| business up. Customers register through Fortify: no business, no limit,
| and a tap waiting to be claimed still is after a look at this page.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->context = app(TenantContext::class);
    $this->account = [
        'name' => 'Nour Amrani',
        'email' => 'nour@cafe-nour.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
    $this->signUp = fn (array $overrides = []) => $this->post(route('business.register.store'), [...$this->account, 'business_name' => 'Café Nour', ...$overrides]);
    $this->businesses = fn (): int => $this->context->bypass(fn (): int => Business::query()->count());
});

it('shows the business sign-up page to guests only', function (): void {
    $this->get(route('business.register'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/register-business'));

    $this->actingAs(User::factory()->create())->get(route('business.register'))->assertRedirect();
});

it('creates the account, its organization and a pending business it owns, not yet set up', function (): void {
    Event::fake([Registered::class]);

    ($this->signUp)(['email' => 'Nour@Cafe-Nour.test'])->assertRedirect(route('dashboard'));

    $owner = User::query()->where('email', 'nour@cafe-nour.test')->firstOrFail();
    $this->assertAuthenticatedAs($owner);
    Event::assertDispatched(Registered::class, fn (Registered $event): bool => $event->user->is($owner));

    $this->context->bypass(function () use ($owner): void {
        $business = Business::query()->with('organization')->sole();

        expect($business->name)->toBe('Café Nour')
            ->and($business->status)->toBe(BusinessStatus::Pending)
            ->and($business->onboarded_at)->toBeNull()
            ->and($business->onboarding_step)->toBeNull()
            ->and($business->organization->type)->toBe(OrganizationType::Independent)
            ->and($business->members()->whereKey($owner->id)->first()?->pivot?->role)->toBe(BusinessRole::Owner)
            ->and($business->organization->admins()->whereKey($owner->id)->first()?->pivot?->role)->toBe(OrganizationRole::OrgAdmin);
    });
});

it('creates no business when a customer registers, whatever the form sends', function (): void {
    $this->post(route('register.store'), [...$this->account, 'business_name' => 'Café Nour'])->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->where('email', 'nour@cafe-nour.test')->exists())->toBeTrue()
        ->and(($this->businesses)())->toBe(0);
});

it('requires a business name, creating nothing without one', function (?string $name): void {
    ($this->signUp)(['business_name' => $name])->assertSessionHasErrors('business_name');

    expect(User::query()->where('email', 'nour@cafe-nour.test')->exists())->toBeFalse()
        ->and(($this->businesses)())->toBe(0);
})->with(['missing' => [null], 'blank' => ['   '], 'too long' => [str_repeat('a', 121)]]);

it('validates the account as any registration does, creating nothing when it fails', function (): void {
    ($this->signUp)(['password_confirmation' => 'something else'])->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(0)
        ->and(($this->businesses)())->toBe(0);
});

it('names the business field in the owner\'s language', function (): void {
    $this->withHeader('Accept-Language', 'fr');

    ($this->signUp)(['business_name' => ''])->assertSessionHasErrors(['business_name' => 'Le champ nom du commerce est obligatoire.']);
});

it('creates the account and the business together or not at all', function (): void {
    Business::creating(fn () => throw new RuntimeException('The business could not be saved.'));

    ($this->signUp)()->assertServerError();

    expect(User::query()->where('email', 'nour@cafe-nour.test')->exists())->toBeFalse()
        ->and(DB::table('organizations')->count())->toBe(0);
});

it('limits business sign-ups from one address, never customers\'', function (): void {
    foreach (range(1, 10) as $attempt) {
        ($this->signUp)(['email' => "owner{$attempt}@cafe.test"]);
        auth()->logout();
    }

    ($this->signUp)(['email' => 'owner11@cafe.test'])->assertTooManyRequests();

    foreach (range(1, 11) as $attempt) {
        $this->post(route('register.store'), [...$this->account, 'email' => "customer{$attempt}@cafe.test"])->assertRedirect();
        auth()->logout();
    }
});

it('leaves a tap waiting to be claimed for the customer who looked at this page', function (): void {
    $this->withSession(['url.intended' => route('taps.claim')])->get(route('business.register'))->assertOk();

    $this->post(route('register.store'), $this->account)->assertRedirect(route('taps.claim'));
});

it('takes a new owner to the dashboard, not a page left behind', function (): void {
    $this->withSession(['url.intended' => route('taps.claim')]);

    ($this->signUp)()->assertRedirect(route('dashboard'));
});

it('refuses an unknown category on Postgres', function (): void {
    $business = $this->context->bypass(fn (): Business => Business::factory()->create());

    expect(fn () => DB::transaction(fn () => DB::table('businesses')->where('id', $business->id)->update(['category' => 'casino'])))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The check constraint is Postgres only');
