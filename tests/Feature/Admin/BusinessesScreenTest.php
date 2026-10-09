<?php

declare(strict_types=1);

use App\Actions\Admin\VerifyBusiness;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\PlatformRole;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Filament\Resources\Businesses\Pages\ListBusinesses;
use App\Filament\Resources\Businesses\Pages\ViewBusiness;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Location;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\LivewireUpdate;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The businesses screen (CHW-34, spec A1)
|--------------------------------------------------------------------------
|
| The platform admin's verification queue: pending businesses first, then
| the verified and suspended ones, each with its owners, sites and stampers.
| Verify, suspend (with a reason) and reinstate call the same Actions as
| anywhere else; a refusal shows as it is. Only the platform admin reaches it.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->status = fn (Business $business, BusinessStatus $status) => $this->context->bypass(fn () => $business->forceFill(['status' => $status])->save());
    $this->fresh = fn (Business $business): Business => $this->context->bypass(fn (): Business => $business->refresh());
    ($this->status)($this->tenants->a2, BusinessStatus::Pending);
    $this->owner = $this->tenants->member(User::factory()->create(['email' => 'owner@cafe-a2.test']), $this->tenants->a2, BusinessRole::Owner);

    $this->screen = function (string $page, Closure $then, array $parameters = []): void {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        Filament::setServingStatus();

        $this->context->bypass(fn () => $then(Livewire::test($page, $parameters)));
    };
});

afterEach(function (): void {
    Filament::setServingStatus(false);
});

it('opens on the verification queue, pending businesses only, and lists the others by status', function (): void {
    ($this->screen)(ListBusinesses::class, fn (Testable $page) => $page
        ->assertCanSeeTableRecords([$this->tenants->a2])
        ->assertCanNotSeeTableRecords([$this->tenants->a1, $this->tenants->b1])
        ->set('activeTab', 'verified')
        ->assertCanSeeTableRecords([$this->tenants->a1, $this->tenants->b1])
        ->assertCanNotSeeTableRecords([$this->tenants->a2])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$this->tenants->a1, $this->tenants->a2, $this->tenants->b1]));
});

it('finds a business by its owner\'s email', function (): void {
    ($this->screen)(ListBusinesses::class, fn (Testable $page) => $page
        ->set('activeTab', 'all')
        ->searchTable('owner@cafe-a2')
        ->assertCanSeeTableRecords([$this->tenants->a2])
        ->assertCanNotSeeTableRecords([$this->tenants->a1, $this->tenants->b1]));
});

it('counts each business\'s open sites and stampers, and shows its last tap', function (): void {
    $stamper = $this->tenants->stamper($this->tenants->a2);
    $this->context->bypass(function () use ($stamper): void {
        Location::factory()->for($this->tenants->a2)->create(['name' => 'A2 old site'])->forceFill(['archived_at' => now()])->save();

        foreach (['2026-10-01 09:00:00', '2026-10-03 18:30:00'] as $at) {
            (new Tap)->forceFill(['nfc_tag_id' => $stamper->nfc_tag_id, 'stamper_id' => $stamper->id, 'business_id' => $stamper->business_id, 'location_id' => $stamper->location_id, 'status' => TapStatus::Rejected, 'rejection' => TapRejection::Replay, 'created_at' => $at])->save();
        }
    });

    ($this->screen)(ListBusinesses::class, fn (Testable $page) => $page
        ->set('activeTab', 'all')
        ->assertTableColumnStateSet('open_locations_count', 1, $this->tenants->a2)
        ->assertTableColumnStateSet('current_stampers_count', 1, $this->tenants->a2)
        ->assertTableColumnStateSet('current_stampers_count', 0, $this->tenants->a1)
        ->assertTableColumnStateSet('taps_max_created_at', '2026-10-03 18:30:00', $this->tenants->a2)
        ->assertTableColumnStateSet('taps_max_created_at', null, $this->tenants->a1));
});

it('verifies, suspends with a reason and reinstates, recording each', function (): void {
    ($this->screen)(ListBusinesses::class, fn (Testable $page) => $page
        ->callAction(TestAction::make('verify')->table($this->tenants->a2))
        ->assertNotified('A2 is verified.')
        ->set('activeTab', 'all')
        ->callAction(TestAction::make('suspend')->table($this->tenants->a2), ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']));

    ($this->screen)(ListBusinesses::class, fn (Testable $page) => $page
        ->set('activeTab', 'all')
        ->callAction(TestAction::make('suspend')->table($this->tenants->a2), ['reason' => 'Chargebacks under review'])
        ->assertNotified('A2 is suspended: its taps, stamps and redemptions stop, and its people lose access.')
        ->assertActionHidden(TestAction::make('suspend')->table($this->tenants->a2))
        ->callAction(TestAction::make('reinstate')->table($this->tenants->a2))
        ->assertNotified('A2 is reinstated.'));

    expect(($this->fresh)($this->tenants->a2)->status)->toBe(BusinessStatus::Verified)
        ->and($this->context->bypass(fn (): array => AuditLog::query()->orderBy('id')->pluck('action')->all()))
        ->toBe(['business.verified', 'business.suspended', 'business.reinstated']);
});

it('shows a refusal as it is', function (): void {
    $this->context->bypass(fn () => $this->tenants->a2->forceFill(['archived_at' => now()])->save());

    ($this->screen)(ListBusinesses::class, fn (Testable $page) => $page
        ->callAction(TestAction::make('verify')->table($this->tenants->a2))
        ->assertNotified('A2 is archived.'));

    expect(($this->fresh)($this->tenants->a2)->status)->toBe(BusinessStatus::Pending);
});

it('shows a business\'s people, sites, stampers and history', function (): void {
    $this->tenants->stamper($this->tenants->a2);
    $uid = $this->context->bypass(fn (): string => $this->tenants->a2->stampers()->firstOrFail()->tag()->firstOrFail()->uid);
    app(VerifyBusiness::class)->handle($this->tenants->a2);

    ($this->screen)(ViewBusiness::class, fn (Testable $page) => $page
        ->assertSee('owner@cafe-a2.test')
        ->assertSee('A2 site')
        ->assertSee($uid)
        ->assertSee('business.verified'), ['record' => $this->tenants->a2->getRouteKey()]);
});

it('opens the screen to the platform admin only, its actions too', function (): void {
    $this->actingAs($this->admin)->get('/admin/businesses')->assertOk()->assertSee('A2');

    $orgAdmin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    foreach ([$this->owner, $orgAdmin, User::factory()->create()] as $user) {
        $this->actingAs($user)->get('/admin/businesses')->assertForbidden();
    }

    Filament::setCurrentPanel('admin');
    Filament::setServingStatus();

    foreach (['verify', 'suspend', 'reinstate'] as $ability) {
        expect(Gate::forUser($this->owner)->allows($ability, $this->tenants->a2))->toBeFalse()
            ->and(Gate::forUser($this->admin)->allows($ability, $this->tenants->a2))->toBeTrue();
    }
});

it('still lists the businesses on a real Livewire update, in bypass()', function (): void {
    $snapshot = LivewireUpdate::snapshot($this, $this->admin, '/admin/businesses', ListBusinesses::class);

    LivewireUpdate::refresh($this, $this->admin, $snapshot)->assertOk()->assertSee('A2');
});

it('speaks the admin\'s language', function (): void {
    $this->admin->forceFill(['locale' => 'fr'])->save();

    $this->actingAs($this->admin)->get('/admin/businesses')->assertOk()->assertSee('File de vérification');
});
