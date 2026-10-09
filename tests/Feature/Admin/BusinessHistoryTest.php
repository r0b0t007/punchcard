<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Enums\StampSource;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Filament\Resources\Businesses\Pages\ViewBusiness;
use App\Filament\Resources\Businesses\RelationManagers\RejectedTapsRelationManager;
use App\Filament\Resources\Businesses\RelationManagers\StampEventsRelationManager;
use App\Filament\Resources\NfcTags\Pages\ManageNfcTags;
use App\Models\Business;
use App\Models\Stamper;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A business's history, and a tag's last tap (CHW-34, spec A1 and A2)
|--------------------------------------------------------------------------
|
| Read-only, for the platform admin: the stamp events and the rejected taps
| of one business, never another's, each at its location's local time. The
| tap log's IP and user agent stay off the screen. The tag screen shows when
| each tag was last tapped.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->customer = User::factory()->create();
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Tokyo'])->save());

    $this->counter = new stdClass;
    $this->counter->next = 1;
    $this->tap = fn (Stamper $stamper, string $at, TapStatus $status = TapStatus::Rejected, ?TapRejection $rejection = TapRejection::Replay): Tap => $this->context->bypass(fn (): Tap => tap((new Tap)->forceFill([
        'nfc_tag_id' => $stamper->nfc_tag_id, 'stamper_id' => $stamper->id, 'business_id' => $stamper->business_id, 'location_id' => $stamper->location_id,
        'status' => $status, 'rejection' => $rejection, 'counter' => $this->counter->next++, 'ip' => '203.0.113.7', 'user_agent' => 'Test phone', 'created_at' => $at,
    ]))->save());

    $this->history = function (string $manager, Business $business, Closure $then): void {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        Filament::setServingStatus();

        $this->context->bypass(fn () => $then(Livewire::test($manager, ['ownerRecord' => $business, 'pageClass' => ViewBusiness::class])));
    };
});

afterEach(function (): void {
    Filament::setServingStatus(false);
});

it('lists a business\'s stamp events, never another\'s, at its location\'s time', function (): void {
    $here = $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => '2026-10-01 23:30:00']);
    $correction = $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['source' => StampSource::Correction, 'qty' => -1, 'reason' => 'Stamped twice']);
    $elsewhere = $this->tenants->stamp($this->enrollment, $this->tenants->a2);

    ($this->history)(StampEventsRelationManager::class, $this->tenants->a1, fn (Testable $table) => $table
        ->assertCanSeeTableRecords([$correction, $here], inOrder: true)
        ->assertCanNotSeeTableRecords([$elsewhere])
        ->assertTableColumnFormattedStateSet('created_at', '2 Oct 2026, 08:30', $here)
        ->assertTableColumnFormattedStateSet('source', 'QR scan', $here)
        ->assertSee('Stamped twice'));
});

it('lists a business\'s rejected taps only, never another\'s, without the IP or user agent', function (): void {
    $a1 = $this->tenants->stamper($this->tenants->a1);
    $this->context->bypass(fn () => $a1->forceFill(['label' => 'Bar'])->save());
    $a2 = $this->tenants->stamper($this->tenants->a2);
    $rejected = ($this->tap)($a1, '2026-10-01 23:30:00');
    $stamped = ($this->tap)($a1, '2026-10-02 08:00:00', TapStatus::Stamped, null);
    $elsewhere = ($this->tap)($a2, '2026-10-02 09:00:00');

    ($this->history)(RejectedTapsRelationManager::class, $this->tenants->a1, fn (Testable $table) => $table
        ->assertCanSeeTableRecords([$rejected])
        ->assertCanNotSeeTableRecords([$stamped, $elsewhere])
        ->assertTableColumnFormattedStateSet('created_at', '2 Oct 2026, 08:30', $rejected)
        ->assertTableColumnFormattedStateSet('rejection', 'Replayed URL', $rejected)
        ->assertTableColumnFormattedStateSet('stamper_id', 'Bar', $rejected)
        ->assertDontSee('203.0.113.7')
        ->assertDontSee('Test phone'));
});

it('names the sources and rejections in the admin\'s language', function (): void {
    app()->setLocale('fr');
    $event = $this->tenants->stamp($this->enrollment, $this->tenants->a1);
    $rejected = ($this->tap)($this->tenants->stamper($this->tenants->a1), '2026-10-01 23:30:00');

    ($this->history)(StampEventsRelationManager::class, $this->tenants->a1, fn (Testable $table) => $table
        ->assertTableColumnFormattedStateSet('source', 'Scan QR', $event));
    ($this->history)(RejectedTapsRelationManager::class, $this->tenants->a1, fn (Testable $table) => $table
        ->assertTableColumnFormattedStateSet('rejection', 'URL rejouée', $rejected));
});

it('shows when each tag was last tapped, on the tag screen', function (): void {
    $tapped = $this->tenants->stamper($this->tenants->a1);
    $quiet = $this->tenants->stamper($this->tenants->a2);
    ($this->tap)($tapped, '2026-10-01 09:00:00', TapStatus::Stamped, null);
    ($this->tap)($tapped, '2026-10-03 18:30:00');

    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setServingStatus();

    $this->context->bypass(fn () => Livewire::test(ManageNfcTags::class)
        ->assertTableColumnStateSet('taps_max_created_at', '2026-10-03 18:30:00', $tapped->tag)
        ->assertTableColumnStateSet('taps_max_created_at', null, $quiet->tag));
});
