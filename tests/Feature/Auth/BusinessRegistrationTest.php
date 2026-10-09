<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Business sign-up (CHW-31, spec B1)
|--------------------------------------------------------------------------
|
| An owner signs up with their account and their business's name in one
| form: the account, an independent organization and a pending business
| are created together or not at all. The onboarding wizard then sets the
| business up. Plain registration (a customer) creates no business.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->context = app(TenantContext::class);
    $this->signUp = fn (array $overrides = []) => $this->post(route('register.store'), [
        'name' => 'Nour Amrani',
        'email' => 'nour@cafe-nour.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'business_name' => 'Café Nour',
        ...$overrides,
    ]);
});

it('shows the business sign-up page to guests only', function (): void {
    $this->get(route('business.register'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/register-business'));

    $this->actingAs(User::factory()->create())->get(route('business.register'))->assertRedirect();
});

it('creates the account, its organization and a pending business it owns, not yet set up', function (): void {
    ($this->signUp)()->assertRedirect(route('dashboard', absolute: false));

    $owner = User::query()->where('email', 'nour@cafe-nour.test')->firstOrFail();
    $this->assertAuthenticatedAs($owner);

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

it('creates no business when a customer registers', function (): void {
    ($this->signUp)(['business_name' => null])->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->where('email', 'nour@cafe-nour.test')->exists())->toBeTrue()
        ->and($this->context->bypass(fn (): int => Business::query()->count()))->toBe(0);
});

it('refuses a business name that is too long, creating nothing', function (): void {
    ($this->signUp)(['business_name' => str_repeat('a', 121)])->assertSessionHasErrors('business_name');

    expect(User::query()->where('email', 'nour@cafe-nour.test')->exists())->toBeFalse();
});

it('creates the account and the business together or not at all', function (): void {
    Business::creating(fn () => throw new RuntimeException('The business could not be saved.'));

    ($this->signUp)()->assertServerError();

    expect(User::query()->where('email', 'nour@cafe-nour.test')->exists())->toBeFalse()
        ->and(DB::table('organizations')->count())->toBe(0);
});

it('limits sign-ups from one address', function (): void {
    foreach (range(1, 10) as $attempt) {
        ($this->signUp)(['email' => "owner{$attempt}@cafe.test"]);
        auth()->logout();
    }

    ($this->signUp)(['email' => 'owner11@cafe.test'])->assertTooManyRequests();
});

it('refuses an unknown category on Postgres', function (): void {
    $business = $this->context->bypass(fn (): Business => Business::factory()->create());

    expect(fn () => DB::transaction(fn () => DB::table('businesses')->where('id', $business->id)->update(['category' => 'casino'])))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The check constraint is Postgres only');
