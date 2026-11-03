<?php

declare(strict_types=1);

use App\Actions\Customers\SummariseCustomer;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\CustomerVisitDay;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The days customers came in (CHW-45, B3)
|--------------------------------------------------------------------------
|
| Site data: a franchisee sees the days at its sites, the org admin the
| organization's, never another organization's, and franchisee A1 never
| sees a visit to A2. Only SummariseCustomer writes them, in bypass().
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $day = function (Business $business, Organization $organization, mixed $card): CustomerVisitDay {
        $user = User::factory()->create();
        $this->tenants->stamp($this->tenants->enroll($user, $card), $business);
        app(SummariseCustomer::class)->handle($business->id, $organization->id, $user->id);

        return $this->context->bypass(fn (): CustomerVisitDay => CustomerVisitDay::query()->where('user_id', $user->id)->sole());
    };
    $this->days = [
        'a1' => $day($this->tenants->a1, $this->tenants->orgA, $this->tenants->cardA),
        'a2' => $day($this->tenants->a2, $this->tenants->orgA, $this->tenants->cardA),
        'b1' => $day($this->tenants->b1, $this->tenants->orgB, $this->tenants->cardB),
    ];
});

it('shows a franchisee the days at its sites, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
    $expected = match ($tenant) {
        'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), ['a1']],
        'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), ['a2']],
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), ['b1']],
        'no tenant' => [null, []],
    };

    expect(CustomerVisitDay::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->days[$key]->id, $expected[1]));
})->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);

it('never lets a franchisee reach another franchisee\'s or organization\'s day', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => CustomerVisitDay::query()->findOrFail($this->days[$other]->id))->toThrow(ModelNotFoundException::class);
})->with(['a2', 'b1']);

it('lets only the summary write days, even for the owner\'s own business', function (string $write): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);
    $own = CustomerVisitDay::query()->findOrFail($this->days['a1']->id);

    expect(fn () => match ($write) {
        'update' => $own->update(['last_at' => now()->addHour()]),
        'delete' => $own->delete(),
        'insert' => CustomerVisitDay::query()->create([...$own->only(['location_id', 'visited_on', 'last_at']), 'user_id' => User::factory()->create()->id]),
    })->toThrow(LogicException::class, 'read from the ledger');
})->with(['update', 'delete', 'insert']);
