<?php

declare(strict_types=1);

use App\Actions\Admin\BusinessStatusRefused;
use App\Actions\Admin\SetAutomationsAccess;
use App\Enums\PlatformRole;
use App\Features\Automations;
use App\Filament\Resources\Businesses\Pages\ViewBusiness;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Letting a business use automations (CHW-46, A1)
|--------------------------------------------------------------------------
|
| A Premium feature, off for every business until the platform admin allows
| it from the business's page, until plans and billing decide it (CHW-52).
| Allowed or stopped, it is kept in the audit log; never offered for an
| archived business. Only the platform admin, only in the admin panel.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->allowed = fn (): bool => Feature::for($this->tenants->a2)->active(Automations::class);
    $this->audits = fn (): array => $this->context->bypass(fn (): array => AuditLog::query()->where('action', 'like', 'business.automations_%')
        ->orderBy('id')->get(['action', 'subject_id', 'actor_label'])->map(fn (AuditLog $entry): array => $entry->only(['action', 'subject_id', 'actor_label']))->all());

    $this->page = function (Closure $then): void {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        Filament::setServingStatus();

        $this->context->bypass(fn () => $then(Livewire::test(ViewBusiness::class, ['record' => $this->tenants->a2->getRouteKey()])));
    };
});

afterEach(function (): void {
    Filament::setServingStatus(false);
});

it('is off for every business until allowed', function (): void {
    expect(($this->allowed)())->toBeFalse()
        ->and(Feature::for($this->tenants->b1)->active(Automations::class))->toBeFalse();
});

it('allows a business from its page, then stops it, each kept in the audit log', function (): void {
    ($this->page)(fn (Testable $page) => $page
        ->assertActionHidden('stopAutomations')
        ->callAction('allowAutomations')
        ->assertNotified('Automations allowed for A2.')
        ->assertActionHidden('allowAutomations')
        ->assertActionVisible('stopAutomations'));

    expect(($this->allowed)())->toBeTrue()
        ->and(Feature::for($this->tenants->a1)->active(Automations::class))->toBeFalse()
        // Filed under the morph map's name, so a renamed class keeps its flag.
        ->and(DB::table('features')->where('name', 'automations')->where('scope', 'business|'.$this->tenants->a2->id)->value('value'))->toBe('true');

    ($this->page)(fn (Testable $page) => $page->callAction('stopAutomations')->assertNotified('Automations stopped for A2.'));

    expect(($this->allowed)())->toBeFalse()
        ->and(($this->audits)())->toBe([
            ['action' => 'business.automations_allowed', 'subject_id' => $this->tenants->a2->id, 'actor_label' => 'admin #'.$this->admin->id],
            ['action' => 'business.automations_stopped', 'subject_id' => $this->tenants->a2->id, 'actor_label' => 'admin #'.$this->admin->id],
        ]);
});

it('changes nothing, and records nothing, when it is already so', function (): void {
    $this->actingAs($this->admin);

    app(SetAutomationsAccess::class)->handle($this->tenants->a2, false);
    app(SetAutomationsAccess::class)->handle($this->tenants->a2, true);
    app(SetAutomationsAccess::class)->handle($this->tenants->a2, true);

    expect(($this->allowed)())->toBeTrue()
        ->and(array_column(($this->audits)(), 'action'))->toBe(['business.automations_allowed']);
});

it('reads the flag again under the lock, so a change made meanwhile is recorded once', function (): void {
    $this->actingAs($this->admin);
    expect(($this->allowed)())->toBeFalse();

    // Another admin allows it meanwhile, after this request read it off.
    DB::table('features')->where('name', 'automations')->where('scope', Feature::serializeScope($this->tenants->a2))->update(['value' => 'true']);
    app(SetAutomationsAccess::class)->handle($this->tenants->a2, true);

    expect(($this->audits)())->toBe([]);
});

it('is not offered for an archived business', function (): void {
    $this->context->bypass(fn () => $this->tenants->a2->forceFill(['archived_at' => now()])->save());

    ($this->page)(fn (Testable $page) => $page->assertActionHidden('allowAutomations')->assertActionHidden('stopAutomations'));
});

it('forgets a change that was rolled back', function (): void {
    // The acting account is deleted meanwhile: the audit entry is refused after the flag was set.
    $gone = User::factory()->create();
    $this->actingAs($gone);
    DB::table('users')->where('id', $gone->id)->delete();

    expect(fn () => app(SetAutomationsAccess::class)->handle($this->tenants->a2, true))->toThrow(LogicException::class, 'no longer exists')
        ->and(($this->allowed)())->toBeFalse()
        ->and(DB::table('features')->where('name', 'automations')->where('scope', 'business|'.$this->tenants->a2->id)->value('value'))->toBe('false');
});

it('refuses an archived business, or one in an archived organization, and records nothing', function (string $archived): void {
    $this->actingAs($this->admin);
    $archived === 'business'
        ? DB::table('businesses')->where('id', $this->tenants->a2->id)->update(['archived_at' => now()])
        : DB::table('organizations')->where('id', $this->tenants->orgA->id)->update(['archived_at' => now()]);

    expect(fn () => app(SetAutomationsAccess::class)->handle($this->tenants->a2, true))->toThrow(BusinessStatusRefused::class, 'A2 is archived.');

    Feature::flushCache();
    expect(($this->allowed)())->toBeFalse()
        ->and(($this->audits)())->toBe([]);
})->with(['business', 'organization']);

it('shows the refusal when the business is archived as the admin acts', function (): void {
    // Archived inside the Action's own transaction, after the page offered it.
    $archived = new stdClass;
    $archived->done = false;
    Event::listen(TransactionBeginning::class, function () use ($archived): void {
        if (! $archived->done) {
            $archived->done = true;
            DB::table('businesses')->where('id', $this->tenants->a2->id)->update(['archived_at' => now()]);
        }
    });

    ($this->page)(fn (Testable $page) => $page->callAction('allowAutomations')->assertNotified('A2 is archived.'));

    Feature::flushCache();
    expect(($this->allowed)())->toBeFalse();
});

it('locks the business no key update, so a tap or a stamp referencing it goes on', function (): void {
    $this->actingAs($this->admin);
    DB::enableQueryLog();

    app(SetAutomationsAccess::class)->handle($this->tenants->a2, true);

    expect(collect(DB::getQueryLog())->pluck('query')->contains(fn (string $sql): bool => str_contains($sql, 'from "businesses"') && str_contains($sql, 'for no key update')))->toBeTrue();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Row locks compile on Postgres only');

it('is the platform admin\'s alone, in the admin panel', function (): void {
    $owner = User::factory()->create();

    expect(Gate::forUser($owner)->allows('setAutomationsAccess', $this->tenants->a2))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('setAutomationsAccess', $this->tenants->a2))->toBeFalse();
});
