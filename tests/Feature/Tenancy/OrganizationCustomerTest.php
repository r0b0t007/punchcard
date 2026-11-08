<?php

declare(strict_types=1);

use App\Actions\Customers\SummariseCustomer;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Organization;
use App\Models\OrganizationCustomer;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A franchise's customers across its sites (CHW-365)
|--------------------------------------------------------------------------
|
| Customer data of the organization, for its org admin alone: a franchisee
| (even of the same organization) sees none of it, nor does another
| organization. Only SummariseCustomer writes it, in bypass().
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $customer = function (Business $business, Organization $organization, mixed $card): OrganizationCustomer {
        $user = User::factory()->create();
        $this->tenants->stamp($this->tenants->enroll($user, $card), $business);
        app(SummariseCustomer::class)->handle($business->id, $organization->id, $user->id);

        return $this->context->bypass(fn (): OrganizationCustomer => OrganizationCustomer::query()->where('user_id', $user->id)->sole());
    };
    $this->customers = [
        'a1' => $customer($this->tenants->a1, $this->tenants->orgA, $this->tenants->cardA),
        'a2' => $customer($this->tenants->a2, $this->tenants->orgA, $this->tenants->cardA),
        'b1' => $customer($this->tenants->b1, $this->tenants->orgB, $this->tenants->cardB),
    ];
});

it('shows the org admin their organization\'s customers, and no franchisee nor another organization any', function (string $tenant): void {
    $expected = match ($tenant) {
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'org admin of B' => [$this->context->set($this->tenants->orgB, orgAdmin: true), ['b1']],
        'franchisee A1\'s owner' => [$this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner), []],
        'franchisee A2\'s staff' => [$this->context->set($this->tenants->orgA, $this->tenants->a2, businessRole: BusinessRole::Staff), []],
        'no tenant' => [null, []],
    };

    expect(OrganizationCustomer::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->customers[$key]->id, $expected[1]));
})->with(['org admin of A', 'org admin of B', 'franchisee A1\'s owner', 'franchisee A2\'s staff', 'no tenant']);

it('lets only the summary write them, even for the org admin', function (string $write): void {
    $this->context->set($this->tenants->orgA, orgAdmin: true);
    $own = OrganizationCustomer::query()->findOrFail($this->customers['a1']->id);

    expect(fn () => match ($write) {
        'update' => $own->update(['visits' => 99]),
        'delete' => $own->delete(),
        'insert' => OrganizationCustomer::query()->create([...$own->only(['visits', 'first_visit_at', 'last_visit_at', 'quiet_at', 'lost_at']), 'user_id' => User::factory()->create()->id]),
    })->toThrow(LogicException::class, 'summarised from the ledger');
})->with(['update', 'delete', 'insert']);
