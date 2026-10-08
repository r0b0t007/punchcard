<?php

declare(strict_types=1);

use App\Actions\Tenancy\ArchiveBusiness;
use App\Enums\BusinessRole;
use App\Enums\PlatformRole;
use App\Enums\StamperStatus;
use App\Filament\Resources\NfcTags\Pages\ManageNfcTags;
use App\Filament\Resources\NfcTags\Tables\NfcTagsTable;
use App\Http\Middleware\PlatformAdminWorksAcrossTenants;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\User;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
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
    // The messages are translated; these tests read them in English, the key (the app's default is French).
    app()->setLocale('en');
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

describe('a real Livewire update', function (): void {
    beforeEach(function (): void {
        // The page's own snapshot, as the browser holds it, sent back to Livewire's update route.
        $this->update = (fn (User $as, string $snapshot): TestResponse => $this->actingAs($as)->withHeaders(['X-Livewire' => '1'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => new stdClass,
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ]));
        $this->snapshotFor = function (User $as): string {
            $html = (string) $this->actingAs($as)->get('/admin/nfc-tags')->assertOk()->getContent();
            preg_match_all('/wire:snapshot="([^"]+)"/', $html, $found);

            return collect($found[1])
                ->map(fn (string $snapshot): string => htmlspecialchars_decode($snapshot, ENT_QUOTES))
                ->first(fn (string $snapshot): bool => str_contains((string) (json_decode($snapshot, true)['memo']['name'] ?? ''), class_basename(ManageNfcTags::class)))
                ?? throw new RuntimeException('No tag provisioning snapshot on the page.');
        };
    });

    it('still lists the tags, since the update runs in bypass() too', function (): void {
        ($this->update)($this->admin, ($this->snapshotFor)($this->admin))
            ->assertOk()
            ->assertSee($this->tag->uid)
            ->assertSee('A1 site');
    });

    it('refuses the admin\'s page replayed by anyone else', function (): void {
        $snapshot = ($this->snapshotFor)($this->admin);
        $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
        $withoutTwoFactor = User::factory()->create();
        $withoutTwoFactor->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));

        foreach ([$owner, $withoutTwoFactor] as $user) {
            ($this->update)($user, $snapshot)->assertForbidden();
        }
    });
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
        ->assertNotified('Registered tag 04A1B2C3D4E5F6 at A2, A2 site (#'.$this->tenants->locationOf($this->tenants->a2)->id.').')
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
        ->assertNotified('Registered tag 04A1B2C3D4E5F6 at A2, Terrace (#'.$terrace->id.').'));
});

it('moves, disables, enables, records a re-key and retires, as the runbook says', function (): void {
    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('move')->table($this->tag), ['business' => $this->tenants->a2->id])
        ->assertNotified('Moved tag '.$this->tag->uid.' to A2, A2 site (#'.$this->tenants->locationOf($this->tenants->a2)->id.').')
        ->callAction(TestAction::make('disable')->table($this->tag))
        ->assertActionHidden(TestAction::make('disable')->table($this->tag))
        ->callAction(TestAction::make('rekeyed')->table($this->tag), ['version' => 2, 'keysChanged' => true])
        ->assertNotified("Tag {$this->tag->uid} is now at key version 2; its counter is unchanged. If you disabled stamper #".($this->context->bypass(fn () => ($this->fresh)($this->tag)->currentStamper()->value('id'))).' for the re-key, enable it and test one tap; if the business had disabled it, leave it.')
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

it('finds a business by name or slug, open ones only', function (): void {
    $this->context->bypass(fn () => app(ArchiveBusiness::class)->handle($this->tenants->a2));

    expect($this->context->bypass(fn (): array => NfcTagsTable::businessesMatching('a1')))->toBe([$this->tenants->a1->id => "A1 ({$this->tenants->a1->slug})"])
        ->and($this->context->bypass(fn (): array => NfcTagsTable::businessesMatching($this->tenants->b1->slug)))->toHaveKey($this->tenants->b1->id)
        ->and($this->context->bypass(fn (): array => NfcTagsTable::businessesMatching('A2')))->toBe([]);

    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('register')->table(), ['uid' => '04A1B2C3D4E5F6', 'business' => $this->tenants->a2->id])
        ->assertHasFormErrors(['business']));
});

it('keeps the newest tags first, however often the others are tapped', function (): void {
    $newer = $this->context->bypass(fn (): NfcTag => NfcTag::factory()->create());
    $this->context->bypass(fn () => NfcTag::query()->whereKey($this->tag->id)->update(['last_counter' => 9, 'updated_at' => now()->addHour()]));

    ($this->screen)(fn (Testable $page) => $page->assertCanSeeTableRecords([$newer, $this->tag], inOrder: true));
});

it('opens the panel in the admin\'s own language', function (): void {
    $this->admin->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($this->admin)->get('/admin/nfc-tags')->assertOk()->assertSee('تهيئة الشرائح');
});

it('speaks the admin\'s language, refusals included', function (): void {
    app()->setLocale('fr');

    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('register')->table(), ['uid' => $this->tag->uid, 'business' => $this->tenants->a2->id])
        ->assertNotified("La puce {$this->tag->uid} est déjà attribuée (borne n° {$this->stamper->id}) : déplacez-la plutôt."));
});

it('clears the location when the business changes', function (): void {
    ($this->screen)(fn (Testable $page) => $page
        ->mountAction(TestAction::make('register')->table())
        ->fillForm(['business' => $this->tenants->a2->id, 'location' => $this->tenants->locationOf($this->tenants->a2)->id])
        ->fillForm(['business' => $this->tenants->b1->id])
        ->assertSchemaStateSet(['location' => null]));
});

it('says when the business had already disabled the stamper', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['status' => StamperStatus::Disabled])->save());

    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('enable')->table($this->tag))
        ->callAction(TestAction::make('disable')->table($this->tag))
        ->assertNotified("Stamper #{$this->stamper->id} at A1, A1 site (#".$this->tenants->locationOf($this->tenants->a1)->id.') is disabled: it refuses every tap, and its arming is cleared.'));
});

it('takes only a location of the business chosen, and refuses one that is gone', function (): void {
    ($this->screen)(fn (Testable $page) => $page
        ->callAction(TestAction::make('register')->table(), ['uid' => '04A1B2C3D4E5F6', 'business' => $this->tenants->a2->id, 'location' => $this->tenants->locationOf($this->tenants->b1)->id])
        ->assertHasFormErrors(['location']));

    expect($this->context->bypass(fn (): bool => NfcTag::query()->where('uid', '04A1B2C3D4E5F6')->exists()))->toBeFalse();
});

describe('who runs in bypass()', function (): void {
    beforeEach(function (): void {
        $this->through = function (User $as, string $route, ?array $paths): string {
            $request = Request::create('/'.$route, 'POST', $paths === null ? [] : ['components' => array_map(
                fn (string $path): array => ['snapshot' => json_encode(['memo' => ['path' => $path]])],
                $paths,
            )]);
            $request->setUserResolver(fn (): User => $as);
            $request->setRouteResolver(fn (): Route => (new Route('POST', $route, []))->name($route === 'admin/nfc-tags' ? 'filament.admin.resources.nfc-tags.index' : 'livewire.update'));

            return (string) app(PlatformAdminWorksAcrossTenants::class)
                ->handle($request, fn (): Response => new Response($this->context->isBypassed() ? 'bypassed' : 'scoped'))
                ->getContent();
        };
    });

    it('bypasses for the platform admin on the panel, and on updates of panel components only', function (): void {
        expect(($this->through)($this->admin, 'admin/nfc-tags', null))->toBe('bypassed')
            ->and(($this->through)($this->admin, 'livewire/update', ['admin/nfc-tags']))->toBe('bypassed')
            ->and(($this->through)($this->admin, 'livewire/update', ['admin', 'admin/nfc-tags']))->toBe('bypassed')
            ->and(($this->through)($this->admin, 'livewire/update', ['admin/nfc-tags', 'cards']))->toBe('scoped')
            ->and(($this->through)($this->admin, 'livewire/update', ['administrator']))->toBe('scoped')
            ->and(($this->through)($this->admin, 'livewire/update', []))->toBe('scoped')
            ->and(($this->through)($this->admin, 'livewire/update', null))->toBe('scoped');
    });

    it('never bypasses for anyone who may not open the panel', function (): void {
        $withoutTwoFactor = User::factory()->create();
        $withoutTwoFactor->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
        $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);

        foreach ([$withoutTwoFactor, $owner] as $user) {
            expect(($this->through)($user, 'admin/nfc-tags', null))->toBe('scoped')
                ->and(($this->through)($user, 'livewire/update', ['admin/nfc-tags']))->toBe('scoped');
        }
    });
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
