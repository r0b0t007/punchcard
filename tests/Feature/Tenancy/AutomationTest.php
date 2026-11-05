<?php

declare(strict_types=1);

use App\Enums\AutomationTrigger;
use App\Enums\BusinessRole;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A business's automations and what they sent (CHW-46, B11)
|--------------------------------------------------------------------------
|
| Site data: a franchisee sees its own, the org admin the organization's,
| never another organization's, and franchisee A1 never sees A2's, even
| for the same person. One automation per trigger; a send is kept as
| claimed, once per occasion, with only its sent_at filled in later.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->user = User::factory()->create();
    $this->automations = [];
    $this->sends = [];

    foreach (['a1' => $this->tenants->a1, 'a2' => $this->tenants->a2, 'b1' => $this->tenants->b1] as $key => $business) {
        $this->automations[$key] = $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $business->id]));
        $this->sends[$key] = $this->context->bypass(fn (): AutomationSend => AutomationSend::factory()->create(['automation_id' => $this->automations[$key]->id, 'user_id' => $this->user->id]));
    }

    $this->tenant = fn (string $tenant): array => match ($tenant) {
        'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), ['a1']],
        'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), ['a2']],
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), ['b1']],
        'no tenant' => [null, []],
    };
});

it('shows a franchisee its own automations and sends, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
    $visible = ($this->tenant)($tenant)[1];

    expect(Automation::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->automations[$key]->id, $visible))
        ->and(AutomationSend::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->sends[$key]->id, $visible));
})->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);

it('never lets a franchisee reach another franchisee\'s or organization\'s automation or send', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => Automation::query()->findOrFail($this->automations[$other]->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => AutomationSend::query()->findOrFail($this->sends[$other]->id))->toThrow(ModelNotFoundException::class);
})->with(['a2', 'b1']);

it('writes an automation only for the business worked in, one per trigger', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(Automation::query()->create(['trigger' => AutomationTrigger::Birthday])->business_id)->toBe($this->tenants->a1->id)
        ->and(fn () => Automation::query()->create(['business_id' => $this->tenants->a2->id, 'trigger' => AutomationTrigger::Birthday]))
        ->toThrow(LogicException::class, 'another business')
        ->and(fn () => Automation::query()->create(['trigger' => AutomationTrigger::WinBack]))->toThrow(QueryException::class);
});

it('never lets the org admin write another organization\'s automation', function (): void {
    $this->context->set($this->tenants->orgA, orgAdmin: true);

    expect(fn () => Automation::query()->create(['business_id' => $this->tenants->b1->id, 'trigger' => AutomationTrigger::Birthday]))
        ->toThrow(LogicException::class);
});

it('claims a send only against its own business\'s automation, for that trigger', function (string $other, string $trigger): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => DB::transaction(fn () => AutomationSend::query()->create([
        'automation_id' => $this->automations[$other]->id, 'user_id' => $this->user->id, 'trigger' => $trigger, 'occasion' => 'quiet:2026-12-01',
    ])))->toThrow(QueryException::class);
})->with([
    'A2\'s automation' => ['a2', 'win_back'],
    'B1\'s automation' => ['b1', 'win_back'],
    'its own, for another trigger' => ['a1', 'birthday'],
]);

it('never deletes an automation, nor changes its trigger, even in bypass', function (): void {
    $automation = $this->automations['a1'];

    expect(fn () => $this->context->bypass(fn () => $automation->delete()))->toThrow(LogicException::class, 'turn it off')
        ->and(fn () => $this->context->bypass(fn () => $automation->update(['trigger' => AutomationTrigger::Birthday])))->toThrow(LogicException::class, 'turn it off')
        ->and($this->context->bypass(fn (): bool => $automation->refresh()->update(['active' => false, 'title' => 'On nous manque'])))->toBeTrue();
});

it('fills in a send\'s sent_at once, and never clears it, even past the model', function (): void {
    $send = fn (): Builder => AutomationSend::query()->whereKey($this->sends['a1']->id);
    $this->context->bypass(fn () => $send()->update(['sent_at' => now()]));

    expect(fn () => $this->context->bypass(fn () => DB::transaction(fn () => $send()->update(['sent_at' => now()->addDay()]))))->toThrow(QueryException::class, 'sent_once')
        ->and(fn () => $this->context->bypass(fn () => DB::transaction(fn () => $send()->update(['sent_at' => null]))))->toThrow(QueryException::class, 'sent_once');
});

it('marks a send sent once, the model with it, and says so', function (): void {
    $send = $this->sends['a1'];
    $first = now()->startOfSecond();

    expect($this->context->bypass(fn (): bool => $send->markSent($first)))->toBeTrue()
        ->and($send->sent_at?->equalTo($first))->toBeTrue()
        ->and($send->isDirty())->toBeFalse()
        ->and($this->context->bypass(fn (): bool => $this->sends['a1']->markSent($first->addHour())))->toBeFalse()
        ->and($send->refresh()->sent_at?->equalTo($first))->toBeTrue();
});

it('makes a send for its automation\'s business and trigger', function (): void {
    $automation = $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $this->tenants->a2->id, 'trigger' => AutomationTrigger::Birthday]));
    $send = $this->context->bypass(fn (): AutomationSend => AutomationSend::factory()->create(['automation_id' => $automation->id]));

    expect($send->only(['business_id', 'organization_id', 'trigger']))
        ->toBe(['business_id' => $this->tenants->a2->id, 'organization_id' => $this->tenants->orgA->id, 'trigger' => AutomationTrigger::Birthday]);
});

it('deletes a send only with the customer, in bypass', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => $this->sends['a1']->delete())->toThrow(LogicException::class, 'as claimed');

    expect($this->context->bypass(fn (): ?bool => $this->sends['a2']->delete()))->toBeTrue();
});

it('starts an automation off, waiting 7 days, with 1 birthday stamp and our message', function (): void {
    $automation = $this->context->bypass(fn (): Automation => Automation::query()->create(['business_id' => $this->tenants->a1->id, 'trigger' => AutomationTrigger::RewardReminder])->refresh());

    expect($automation->only(['active', 'days', 'stamps', 'title', 'body']))->toBe(['active' => false, 'days' => 7, 'stamps' => 1, 'title' => null, 'body' => null]);
});

it('sends once per occasion, and keeps a send as claimed but for its sent_at, even in bypass', function (): void {
    $send = $this->sends['a1'];

    $this->context->bypass(fn (): bool => $send->markSent(now()));

    expect($send->refresh()->sent_at)->not->toBeNull()
        ->and(fn () => $this->context->bypass(fn () => AutomationSend::factory()->create(['automation_id' => $send->automation_id, 'user_id' => $this->user->id])))
        ->toThrow(QueryException::class)
        ->and(fn () => $this->context->bypass(fn () => $send->update(['occasion' => 'quiet:2026-12-01'])))->toThrow(LogicException::class, 'as claimed');
});

it('goes with the customer\'s account', function (): void {
    $this->context->bypass(fn () => $this->user->delete());

    expect($this->context->bypass(fn (): int => AutomationSend::query()->count()))->toBe(0)
        ->and($this->context->bypass(fn (): int => Automation::query()->count()))->toBe(3);
});

it('goes with the business, its sends and automations at once', function (): void {
    $business = $this->context->bypass(fn (): Business => Business::factory()->create());
    $automation = $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $business->id]));
    $this->context->bypass(fn () => AutomationSend::factory()->create(['automation_id' => $automation->id, 'user_id' => $this->user->id]));

    DB::table('businesses')->where('id', $business->id)->delete();

    expect(DB::table('automations')->where('business_id', $business->id)->count())->toBe(0)
        ->and(DB::table('automation_sends')->where('business_id', $business->id)->count())->toBe(0)
        ->and(DB::table('automation_sends')->count())->toBe(3);
});

it('files each automation and send under its business\'s organization', function (): void {
    $organizations = fn (array $rows): array => array_map(fn (Automation|AutomationSend $row): int => $row->organization_id, $rows);
    $expected = ['a1' => $this->tenants->orgA->id, 'a2' => $this->tenants->orgA->id, 'b1' => $this->tenants->orgB->id];

    expect($organizations($this->automations))->toBe($expected)
        ->and($organizations($this->sends))->toBe($expected);
});

it('keeps a trigger, a wait or a bonus it does not know out of the database too', function (array $values): void {
    // Each in its own savepoint: a failed statement aborts the transaction it is in.
    $row = ['organization_id' => $this->tenants->orgA->id, 'business_id' => $this->tenants->a2->id, 'trigger' => 'reward_reminder', 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => DB::transaction(fn () => DB::table('automations')->insert([...$row, ...$values])))
        ->toThrow(function (QueryException $refused): void {
            expect($refused->getCode())->toBe('23514');
        });
})->with([
    'an unknown trigger' => [['trigger' => 'anniversary']],
    'no wait' => [['days' => 0]],
    'a wait past 60 days' => [['days' => 61]],
    'no bonus' => [['stamps' => 0]],
    'a bonus past 3' => [['stamps' => 4]],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'A Postgres CHECK.');
