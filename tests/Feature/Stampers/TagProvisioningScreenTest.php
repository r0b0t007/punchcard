<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\PlatformRole;
use App\Enums\StamperStatus;
use App\Filament\Resources\NfcTags\Pages\ManageNfcTags;
use App\Http\Middleware\PlatformAdminWorksAcrossTenants;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\User;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The tag provisioning screen (CHW-138, spec A2)
|--------------------------------------------------------------------------
|
| The platform admin's Filament page over NFC tags: each tag, its key
| version, counter and current stamper, and the runbook's actions (register,
| move, disable, enable, re-keyed, retire), calling the same Actions as the
| commands. Only the platform admin reaches it; no key ever reaches the page.
|
*/

beforeEach(function (): void {
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->tag = $this->context->bypass(fn (): NfcTag => $this->stamper->tag()->firstOrFail());
    $this->fresh = fn (NfcTag|Stamper $model): NfcTag|Stamper => $this->context->bypass(fn (): NfcTag|Stamper => $model->refresh());

    // As the panel serves an action: Filament serving the admin panel, the admin's request in bypass().
    $this->screen = function (Closure $then, ?User $as = null): void {
        $this->actingAs($as ?? $this->admin);
        Filament::setCurrentPanel('admin');
        Filament::setServingStatus();

        $this->context->bypass(fn () => $then(Livewire::test(ManageNfcTags::class)));
    };
});

afterEach(function (): void {
    Filament::setServingStatus(false);
});

it('shows the platform admin each tag and where it is, and nobody else', function (): void {
    $this->actingAs($this->admin)->get('/admin/nfc-tags')
        ->assertOk()
        ->assertSee($this->tag->uid)
        ->assertSee('A1 site');

    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $orgAdmin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    foreach ([$owner, $staff, $orgAdmin, User::factory()->create()] as $user) {
        $this->actingAs($user)->get('/admin/nfc-tags')->assertForbidden();
    }
});

it('authenticates the panel\'s Livewire updates and runs them in bypass(), as its pages', function (): void {
    expect(Livewire::getPersistentMiddleware())->toContain(Authenticate::class, PlatformAdminWorksAcrossTenants::class);
});

it('never puts a tag key on the page', function (): void {
    $keys = app(KeyDiversifier::class);
    $html = $this->actingAs($this->admin)->get('/admin/nfc-tags')->assertOk()->getContent();

    foreach ([$keys->fileReadKey($this->tag->uid, 1), $keys->metaReadKey(1)] as $key) {
        expect(stripos((string) $html, bin2hex($key)))->toBeFalse();
    }
});

it('registers a tag, and shows a refusal as it is', function (): void {
    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('register')->table(), ['uid' => '04 a1 b2 c3 d4 e5 f6', 'business' => $this->tenants->a2->id, 'label' => 'Bar'])
        ->assertHasNoFormErrors()
        ->assertNotified('Registered tag 04A1B2C3D4E5F6 at A2, A2 site.')
        ->callAction(TestAction::make('register')->table(), ['uid' => $this->tag->uid, 'business' => $this->tenants->a2->id])
        ->assertNotified("Tag {$this->tag->uid} is already assigned (stamper #{$this->stamper->id}): move it instead."));

    $stamper = $this->context->bypass(fn (): Stamper => NfcTag::query()->where('uid', '04A1B2C3D4E5F6')->firstOrFail()->currentStamper()->firstOrFail());

    expect($stamper->business_id)->toBe($this->tenants->a2->id)
        ->and($stamper->label)->toBe('Bar');
});

it('registers a tag at the location chosen, when the business has several', function (): void {
    $terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a2)->create(['name' => 'Terrace']));

    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('register')->table(), ['uid' => '04A1B2C3D4E5F6', 'business' => $this->tenants->a2->id, 'location' => $terrace->id])
        ->assertNotified('Registered tag 04A1B2C3D4E5F6 at A2, Terrace.'));
});

it('moves, disables, enables, records a re-key and retires, as the runbook says', function (): void {
    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('move')->table($this->tag), ['business' => $this->tenants->a2->id])
        ->assertNotified('Moved tag '.$this->tag->uid.' to A2, A2 site.')
        ->callAction(TestAction::make('disable')->table($this->tag))
        ->assertActionHidden(TestAction::make('disable')->table($this->tag))
        ->callAction(TestAction::make('rekeyed')->table($this->tag), ['version' => 2, 'keysChanged' => true])
        ->assertNotified("Tag {$this->tag->uid} is now at key version 2.")
        ->callAction(TestAction::make('enable')->table($this->tag))
        ->assertActionHidden(TestAction::make('enable')->table($this->tag)));

    $current = $this->context->bypass(fn (): Stamper => ($this->fresh)($this->tag)->currentStamper()->firstOrFail());

    expect($current->business_id)->toBe($this->tenants->a2->id)
        ->and($current->status)->toBe(StamperStatus::Active)
        ->and(($this->fresh)($this->tag)->key_version)->toBe(2);

    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('retire')->table($this->tag))
        ->assertNotified("Retired tag {$this->tag->uid}.")
        ->assertActionHidden(TestAction::make('retire')->table($this->tag))
        ->assertActionHidden(TestAction::make('move')->table($this->tag)));

    expect(($this->fresh)($this->tag)->retired_at)->not->toBeNull();
});

it('records a re-key only once the admin confirms which keys changed', function (): void {
    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('rekeyed')->table($this->tag), ['version' => 2])
        ->assertHasFormErrors(['keysChanged' => 'accepted']));

    expect(($this->fresh)($this->tag)->key_version)->toBe(1);
});

it('shows the free tags alone when asked', function (): void {
    $free = $this->context->bypass(fn (): NfcTag => NfcTag::factory()->create());

    ($this->screen)(fn (Testable $page) => $page
        ->assertCanSeeTableRecords([$this->tag, $free])
        ->filterTable('unassigned')
        ->assertCanSeeTableRecords([$free])
        ->assertCanNotSeeTableRecords([$this->tag]));
});

it('never opens the page or its actions to anyone but the platform admin', function (string $who): void {
    $user = match ($who) {
        'owner' => $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner),
        'staff' => $this->tenants->member(User::factory()->create(), $this->tenants->a1),
        'org admin' => $this->tenants->admin(User::factory()->create(), $this->tenants->orgA),
    };

    ($this->screen)(fn (Testable $page) => $page->assertForbidden(), $user);

    foreach (['register', 'move', 'setStatus', 'rekey', 'retire'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, NfcTag::class))->toBeFalse()
            ->and(Gate::forUser($this->admin)->allows($ability, NfcTag::class))->toBeTrue();
    }
})->with(['owner', 'staff', 'org admin']);
