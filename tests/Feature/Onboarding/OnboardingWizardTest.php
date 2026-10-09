<?php

declare(strict_types=1);

use App\Actions\Account\MustHandOverBusiness;
use App\Actions\Onboarding\CancelSetup;
use App\Actions\Onboarding\CompleteStep;
use App\Actions\Tenancy\CreateIndependentBusiness;
use App\Enums\BusinessCategory;
use App\Enums\BusinessRole;
use App\Enums\OnboardingStep;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The onboarding wizard, first three steps (CHW-31, spec B2)
|--------------------------------------------------------------------------
|
| A new owner, or anyone signed in starting a business, sets it up step by
| step: business details, first location, logo. Each step is saved as it is
| done, and the owner resumes where they stopped. The wizard only ever works
| on the user's own unfinished business: no route names a business.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->context = app(TenantContext::class);
    $this->owner = User::factory()->create();
    $this->business = app(CreateIndependentBusiness::class)->handle($this->owner, 'Café Nour');
    $this->fresh = fn (Business $business): Business => $this->context->bypass(fn (): Business => $business->refresh());
    $this->step = fn (Business $business, OnboardingStep $step) => $this->context->bypass(fn () => $business->forceFill(['onboarding_step' => $step])->save());
});

describe('who goes to the wizard', function (): void {
    it('sends an owner whose business is not set up from the dashboard to the wizard', function (): void {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect(route('onboarding.show'));
    });

    it('leaves customers and owners of set-up businesses on the dashboard', function (): void {
        $tenants = Tenants::make();
        $owner = $tenants->member(User::factory()->create(), $tenants->a1, BusinessRole::Owner);

        foreach ([User::factory()->create(), $owner] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
        }
    });

    it('asks for a verified email first', function (): void {
        $this->owner->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->owner)->get(route('onboarding.show'))->assertRedirect(route('verification.notice'));
    });

    it('resumes at the step reached, and never skips ahead', function (): void {
        ($this->step)($this->business, OnboardingStep::Location);

        $this->actingAs($this->owner)->get(route('onboarding.show'))->assertRedirect(route('onboarding.step', 'location'));
        $this->get(route('onboarding.step', 'logo'))->assertRedirect(route('onboarding.step', 'location'));
        $this->get(route('onboarding.step', 'business'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('onboarding/business')
                ->where('business.name', 'Café Nour')
                ->where('steps.current', 'location'));
    });
});

describe('the business step', function (): void {
    it('saves the name and category, renaming the organization, never the slug', function (): void {
        $slug = $this->business->slug;

        $this->actingAs($this->owner)->post(route('onboarding.business'), ['name' => 'Café Nour Tanger', 'category' => 'cafe'])
            ->assertRedirect(route('onboarding.step', 'location'));

        $business = ($this->fresh)($this->business);
        expect($business->name)->toBe('Café Nour Tanger')
            ->and($business->category)->toBe(BusinessCategory::Cafe)
            ->and($business->slug)->toBe($slug)
            ->and($business->onboarding_step)->toBe(OnboardingStep::Location)
            ->and($this->context->bypass(fn (): string => Organization::query()->findOrFail($business->organization_id)->name))->toBe('Café Nour Tanger');
    });

    it('refuses an unknown category', function (): void {
        $this->actingAs($this->owner)->post(route('onboarding.business'), ['name' => 'Café Nour', 'category' => 'casino'])
            ->assertSessionHasErrors('category');
    });

    it('starts a business for someone signed in who has none to finish', function (): void {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get(route('onboarding.show'))->assertRedirect(route('onboarding.step', 'business'));
        $this->get(route('onboarding.step', 'location'))->assertRedirect(route('onboarding.step', 'business'));
        $this->post(route('onboarding.business'), ['name' => 'Barbier Atlas', 'category' => 'barber'])
            ->assertRedirect(route('onboarding.step', 'location'));

        $business = $this->context->bypass(fn (): Business => Business::query()->where('name', 'Barbier Atlas')->sole());
        expect($business->category)->toBe(BusinessCategory::Barber)
            ->and($business->onboarding_step)->toBe(OnboardingStep::Location)
            ->and($this->context->bypass(fn (): bool => $business->members()->whereKey($customer->id)->wherePivot('role', BusinessRole::Owner->value)->exists()))->toBeTrue()
            ->and(session(SetTenant::SESSION_KEY))->toBe('business:'.$business->id);
    });

    it('resumes the unfinished business instead of starting a second', function (): void {
        $this->actingAs($this->owner)->post(route('onboarding.business'), ['name' => 'Café Nour', 'category' => 'cafe']);
        $this->post(route('onboarding.business'), ['name' => 'Café Nour bis', 'category' => 'cafe']);

        expect($this->context->bypass(fn (): int => Business::query()->count()))->toBe(1);
    });

    it('lets the owner of a set-up business start another, in its own organization', function (): void {
        $this->context->bypass(fn () => $this->business->forceFill(['onboarded_at' => now()])->save());

        $this->actingAs($this->owner)->post(route('onboarding.business'), ['name' => 'Café Nour 2', 'category' => 'cafe'])
            ->assertRedirect(route('onboarding.step', 'location'));

        $second = $this->context->bypass(fn (): Business => Business::query()->where('name', 'Café Nour 2')->sole());
        expect($second->organization_id)->not->toBe($this->business->organization_id);
    });
});

describe('the location step', function (): void {
    beforeEach(fn () => ($this->step)($this->business, OnboardingStep::Location));

    it('creates the first location in its timezone, and updates it when the step is done again', function (): void {
        $this->actingAs($this->owner)->put(route('onboarding.location'), ['name' => 'Café Nour Marshan', 'address' => '12 rue de la Plage, Tanger', 'timezone' => 'Africa/Casablanca'])
            ->assertRedirect(route('onboarding.step', 'logo'));
        $this->put(route('onboarding.location'), ['name' => 'Café Nour Marshan', 'address' => '14 rue de la Plage, Tanger', 'timezone' => 'Europe/Paris']);

        $location = $this->context->bypass(fn (): Location => Location::query()->where('business_id', $this->business->id)->sole());
        expect($location->address)->toBe('14 rue de la Plage, Tanger')
            ->and($location->timezone)->toBe('Europe/Paris')
            ->and(($this->fresh)($this->business)->onboarding_step)->toBe(OnboardingStep::Logo);
    });

    it('refuses an unknown timezone', function (): void {
        $this->actingAs($this->owner)->put(route('onboarding.location'), ['name' => 'Marshan', 'address' => 'Tanger', 'timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');
    });
});

describe('the logo step', function (): void {
    beforeEach(function (): void {
        Storage::fake('public');
        ($this->step)($this->business, OnboardingStep::Logo);
        $this->logo = fn (): ?string => $this->context->bypass(fn (): ?string => Organization::query()->findOrFail($this->business->organization_id)->logo_path);
    });

    it('stores the logo under a random name, replacing the old file', function (): void {
        $this->actingAs($this->owner)->post(route('onboarding.logo'), ['logo' => UploadedFile::fake()->image('my logo.png', 400, 400)])
            ->assertRedirect(route('onboarding.step', 'card'));
        $first = ($this->logo)();

        ($this->step)($this->business, OnboardingStep::Logo);
        $this->post(route('onboarding.logo'), ['logo' => UploadedFile::fake()->image('new.webp', 400, 400)]);
        $second = ($this->logo)();

        expect($first)->toStartWith('logos/')->not->toContain('my logo')
            ->and($second)->not->toBe($first);
        Storage::disk('public')->assertMissing((string) $first);
        Storage::disk('public')->assertExists((string) $second);
        expect(($this->fresh)($this->business)->onboarding_step)->toBe(OnboardingStep::Card);
    });

    it('refuses an SVG, a PDF, a file over 2 MB and a tiny image', function (UploadedFile $file): void {
        $this->actingAs($this->owner)->post(route('onboarding.logo'), ['logo' => $file])->assertSessionHasErrors('logo');

        expect(($this->logo)())->toBeNull();
    })->with([
        'svg' => fn () => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        'pdf' => fn () => UploadedFile::fake()->create('logo.pdf', 40, 'application/pdf'),
        'too big' => fn () => UploadedFile::fake()->image('logo.png', 400, 400)->size(2049),
        'too small' => fn () => UploadedFile::fake()->image('logo.png', 32, 32),
    ]);

    it('can be skipped', function (): void {
        $this->actingAs($this->owner)->post(route('onboarding.logo.skip'))->assertRedirect(route('onboarding.step', 'card'));

        expect(($this->logo)())->toBeNull()
            ->and(($this->fresh)($this->business)->onboarding_step)->toBe(OnboardingStep::Card);
    });
});

describe('cancelling setup', function (): void {
    it('closes the unfinished business, so it no longer blocks the dashboard or deleting the account', function (): void {
        $this->actingAs($this->owner)->post(route('onboarding.cancel'))->assertRedirect(route('dashboard'));

        expect(($this->fresh)($this->business)->archived_at)->not->toBeNull()
            ->and(app(MustHandOverBusiness::class)->handle($this->owner))->toBeFalse();
        $this->get(route('dashboard'))->assertOk();
    });

    it('never closes a business that is set up', function (): void {
        $this->context->bypass(fn () => $this->business->forceFill(['onboarded_at' => now()])->save());

        $this->actingAs($this->owner)->post(route('onboarding.cancel'))->assertRedirect(route('dashboard'));

        expect(($this->fresh)($this->business)->archived_at)->toBeNull();
    });
});

it('only ever works on the user\'s own business', function (): void {
    $stranger = User::factory()->create();
    app(CreateIndependentBusiness::class)->handle($stranger, 'Salon Amal');

    $this->actingAs($stranger)->post(route('onboarding.business'), ['name' => 'Renamed', 'category' => 'salon']);

    expect(($this->fresh)($this->business)->name)->toBe('Café Nour');
});

it('leaves staff of a business being set up out of its wizard', function (): void {
    $staff = User::factory()->create();
    $this->context->bypass(fn () => $this->business->members()->attach($staff, ['role' => BusinessRole::Staff->value]));

    $this->actingAs($staff)->get(route('dashboard'))->assertOk();
});

it('never moves an owner back when an earlier step is done again', function (): void {
    ($this->step)($this->business, OnboardingStep::Logo);

    $this->actingAs($this->owner)->post(route('onboarding.business'), ['name' => 'Café Nour', 'category' => 'cafe'])
        ->assertRedirect(route('onboarding.step', 'location'));

    expect(($this->fresh)($this->business)->onboarding_step)->toBe(OnboardingStep::Logo);
});

it('leaves a business that finished setup as it is', function (): void {
    $this->context->bypass(fn () => $this->business->forceFill(['onboarding_step' => OnboardingStep::Shipping, 'onboarded_at' => now()->subDay()])->save());
    $finishedAt = ($this->fresh)($this->business)->onboarded_at?->toDateTimeString();

    app(CompleteStep::class)->handle($this->business, OnboardingStep::Shipping);
    app(CancelSetup::class)->handle(($this->fresh)($this->business));

    $business = ($this->fresh)($this->business);
    expect($business->onboarded_at?->toDateTimeString())->toBe($finishedAt)
        ->and($business->archived_at)->toBeNull();
});
