<?php

declare(strict_types=1);

use App\Actions\Customers\SummariseCustomer;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A business's customers (CHW-44, B4)
|--------------------------------------------------------------------------
|
| Site data: a franchisee sees who came in to it, the org admin the
| organization's, never another organization's, and franchisee A1 never
| sees who only visited A2. Only SummariseCustomer writes it, in bypass().
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $customer = fn (Business $business, Organization $organization, mixed $card): BusinessCustomer => (function () use ($business, $organization, $card): BusinessCustomer {
        $user = User::factory()->create();
        $this->tenants->stamp($this->tenants->enroll($user, $card), $business);
        app(SummariseCustomer::class)->handle($business->id, $organization->id, $user->id);

        return $this->context->bypass(fn (): BusinessCustomer => BusinessCustomer::query()->where('user_id', $user->id)->sole());
    })();
    $this->customers = [
        'a1' => $customer($this->tenants->a1, $this->tenants->orgA, $this->tenants->cardA),
        'a2' => $customer($this->tenants->a2, $this->tenants->orgA, $this->tenants->cardA),
        'b1' => $customer($this->tenants->b1, $this->tenants->orgB, $this->tenants->cardB),
    ];
});

it('shows a franchisee who came in to it, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
    $expected = match ($tenant) {
        'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), ['a1']],
        'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), ['a2']],
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), ['b1']],
        'no tenant' => [null, []],
    };

    expect(BusinessCustomer::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->customers[$key]->id, $expected[1]));
})->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);

it('never lets a franchisee reach another franchisee\'s or organization\'s customer', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => BusinessCustomer::query()->findOrFail($this->customers[$other]->id))->toThrow(ModelNotFoundException::class);
})->with(['a2', 'b1']);

it('lets only the summary write customers, even for the owner\'s own business', function (string $write): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);
    $own = BusinessCustomer::query()->findOrFail($this->customers['a1']->id);

    expect(fn () => match ($write) {
        'update' => $own->update(['visits' => 99]),
        'delete' => $own->delete(),
        'insert' => BusinessCustomer::query()->create([...$own->only(['visits', 'first_visit_at', 'last_visit_at', 'quiet_at', 'lost_at']), 'user_id' => User::factory()->create()->id]),
    })->toThrow(LogicException::class, 'summarised from the ledger');
})->with(['update', 'delete', 'insert']);
