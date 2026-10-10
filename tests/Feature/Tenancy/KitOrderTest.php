<?php

declare(strict_types=1);

use App\Actions\Tenancy\ResolveTenant;
use App\Enums\BusinessRole;
use App\Models\KitOrder;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Stamper kit orders (CHW-31; tracking, CHW-57)
|--------------------------------------------------------------------------
|
| A kit order is site data: a franchisee sees its own, the org admin the
| organization's, never another organization's, and franchisee A1 never
| sees A2's. Its owner requests it; its status is the platform's to move
| (fulfilment), in bypass().
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->orders = $this->context->bypass(fn (): array => [
        'a1' => KitOrder::factory()->for($this->tenants->a1)->create(),
        'a2' => KitOrder::factory()->for($this->tenants->a2)->create(),
        'b1' => KitOrder::factory()->for($this->tenants->b1)->create(),
    ]);
});

it('shows a franchisee its own kit orders, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
    $expected = match ($tenant) {
        'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), ['a1']],
        'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), ['a2']],
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), ['b1']],
        'no tenant' => [null, []],
    };

    expect(KitOrder::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->orders[$key]->id, $expected[1]));
})->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);

it('never lets a franchisee change another franchisee\'s or organization\'s order', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(KitOrder::query()->whereKey($this->orders[$other]->id)->update(['city' => 'Elsewhere']))->toBe(0);
})->with(['a2', 'b1']);

it('lets the owner fix the address, never the status', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);
    $order = KitOrder::query()->findOrFail($this->orders['a1']->id);

    $order->forceFill(['city' => 'Tétouan'])->save();
    expect($order->refresh()->city)->toBe('Tétouan');

    KitOrder::query()->whereKey($order->id)->update(['status' => 'shipped']);
})->throws(LogicException::class, 'status');

it('keeps kit orders: an owner never deletes one', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    KitOrder::query()->findOrFail($this->orders['a1']->id)->delete();
})->throws(LogicException::class, 'kept');

it('lets the owner correct a requested order only, never one fulfilment has', function (): void {
    $this->context->bypass(fn () => DB::table('kit_orders')->where('id', $this->orders['a1']->id)->update(['status' => 'shipped']));
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    KitOrder::query()->findOrFail($this->orders['a1']->id)->forceFill(['city' => 'Tétouan'])->save();
})->throws(LogicException::class, 'Only a requested kit order')
    ->skip(fn (): bool => DB::getDriverName() === 'pgsql', 'Postgres allows only the requested status until fulfilment (CHW-57)');

it('lets only who runs the business request its kit', function (string $who, string $business, bool $allowed): void {
    $people = [
        'owner of A1' => fn (): User => $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner),
        'staff of A1' => fn (): User => $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff),
        'owner of B1' => fn (): User => $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner),
    ];
    $user = $people[$who]();
    app(ResolveTenant::class)->handle($user);

    expect(Gate::forUser($user)->allows('create', [KitOrder::class, $this->tenants->{$business}]))->toBe($allowed);
})->with([
    'the owner, for their business' => ['owner of A1', 'a1', true],
    'staff' => ['staff of A1', 'a1', false],
    'the owner, for a sister franchisee' => ['owner of A1', 'a2', false],
    'another organization\'s owner' => ['owner of B1', 'a1', false],
]);
