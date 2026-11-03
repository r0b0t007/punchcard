<?php

declare(strict_types=1);

use App\Actions\Stats\RollUpDay;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessDailyStat;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A business's daily figures (CHW-45, B3)
|--------------------------------------------------------------------------
|
| Site data: a franchisee sees its own days, the org admin the
| organization's, never another organization's, and franchisee A1 never
| sees A2's. Only RollUpDay writes them, in bypass().
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $day = function (Business $business): BusinessDailyStat {
        app(RollUpDay::class)->handle($business, '2026-10-10');

        return $this->context->bypass(fn (): BusinessDailyStat => BusinessDailyStat::query()->where('business_id', $business->id)->whereNull('location_id')->sole());
    };
    $this->days = ['a1' => $day($this->tenants->a1), 'a2' => $day($this->tenants->a2), 'b1' => $day($this->tenants->b1)];
});

it('shows a franchisee its days, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
    $expected = match ($tenant) {
        'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), ['a1']],
        'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), ['a2']],
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), ['b1']],
        'no tenant' => [null, []],
    };

    expect(BusinessDailyStat::query()->whereNull('location_id')->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->days[$key]->id, $expected[1]));
})->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);

it('never lets a franchisee reach another franchisee\'s or organization\'s day', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => BusinessDailyStat::query()->findOrFail($this->days[$other]->id))->toThrow(ModelNotFoundException::class);
})->with(['a2', 'b1']);

it('lets only the rollup write days, even for the owner\'s own business', function (string $write): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);
    $own = BusinessDailyStat::query()->findOrFail($this->days['a1']->id);

    expect(fn () => match ($write) {
        'update' => $own->update(['visits' => 9]),
        'delete' => $own->delete(),
        'insert' => BusinessDailyStat::query()->create([...$own->only(['visits', 'new_customers', 'stamps', 'rewards_unlocked', 'rewards_redeemed']), 'day' => '2026-10-11']),
    })->toThrow(LogicException::class, 'rolled up from the ledger');
})->with(['update', 'delete', 'insert']);
