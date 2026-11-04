<?php

declare(strict_types=1);

use App\Enums\AutomationTrigger;
use App\Enums\BusinessRole;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
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
        $this->sends[$key] = $this->context->bypass(fn (): AutomationSend => AutomationSend::query()->create([
            'business_id' => $business->id, 'automation_id' => $this->automations[$key]->id, 'user_id' => $this->user->id,
            'trigger' => AutomationTrigger::WinBack, 'occasion' => 'quiet:2026-11-04',
        ]));
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

it('starts an automation off, waiting 7 days, with 1 birthday stamp and our message', function (): void {
    $automation = $this->context->bypass(fn (): Automation => Automation::query()->create(['business_id' => $this->tenants->a1->id, 'trigger' => AutomationTrigger::RewardReminder])->refresh());

    expect($automation->only(['active', 'days', 'stamps', 'title', 'body']))->toBe(['active' => false, 'days' => 7, 'stamps' => 1, 'title' => null, 'body' => null]);
});

it('sends once per occasion, and keeps a send as claimed but for its sent_at, even in bypass', function (): void {
    $send = $this->sends['a1'];

    $this->context->bypass(fn () => AutomationSend::query()->whereKey($send->id)->whereNull('sent_at')->update(['sent_at' => now()]));

    expect($send->refresh()->sent_at)->not->toBeNull()
        ->and(fn () => $this->context->bypass(fn () => AutomationSend::query()->create([
            'business_id' => $this->tenants->a1->id, 'automation_id' => $send->automation_id, 'user_id' => $this->user->id,
            'trigger' => AutomationTrigger::WinBack, 'occasion' => 'quiet:2026-11-04',
        ])))->toThrow(QueryException::class)
        ->and(fn () => $this->context->bypass(fn () => $send->update(['occasion' => 'quiet:2026-12-01'])))->toThrow(LogicException::class, 'as claimed')
        ->and(fn () => $this->context->bypass(fn () => $send->delete()))->toThrow(LogicException::class, 'as claimed');
});

it('goes with the customer\'s account', function (): void {
    $this->context->bypass(fn () => $this->user->delete());

    expect($this->context->bypass(fn (): int => AutomationSend::query()->count()))->toBe(0)
        ->and($this->context->bypass(fn (): int => Automation::query()->count()))->toBe(3);
});

it('files each automation and send under its business\'s organization', function (): void {
    $organizations = fn (array $rows): array => array_map(fn (Automation|AutomationSend $row): int => $row->organization_id, $rows);
    $expected = ['a1' => $this->tenants->orgA->id, 'a2' => $this->tenants->orgA->id, 'b1' => $this->tenants->orgB->id];

    expect($organizations($this->automations))->toBe($expected)
        ->and($organizations($this->sends))->toBe($expected);
});

it('keeps a trigger, a wait or a bonus it does not know out of the database too', function (array $values): void {
    // Each in its own savepoint: a failed statement aborts the transaction it is in.
    expect(fn () => $this->context->bypass(fn () => DB::transaction(fn () => Automation::query()->whereKey($this->automations['a1']->id)->update($values))))
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
