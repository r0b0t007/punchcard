<?php

declare(strict_types=1);

use App\Actions\Customers\ListCustomers;
use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The Customers page (CHW-44, B4, design 15a)
|--------------------------------------------------------------------------
|
| Whoever runs the business sees who came in: a tab per segment with its
| count, a first-name search, 25 a page, each customer by first name and
| initial. Never staff, and franchisee A1 never sees who only visited A2.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    // A fixed offset (UTC+4): 22:00 UTC is already tomorrow there.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
    $this->as = fn (User $user, ?Business $business = null) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.($business ?? $this->tenants->a1)->id]);
    $this->open = fn (array $query = [], ?User $user = null) => ($this->as)($user ?? $this->owner)->get(route('customers.index', $query));

    // An active regular unless told otherwise: 3 visits, the last 2 days ago.
    $this->customer = fn (string $name, array $values = [], ?Business $business = null): BusinessCustomer => $this->context->bypass(function () use ($name, $values, $business): BusinessCustomer {
        $business ??= $this->tenants->a1;

        return BusinessCustomer::query()->create([
            'organization_id' => $business->organization_id,
            'business_id' => $business->id,
            'user_id' => User::factory()->create(['name' => $name])->id,
            'visits' => 3,
            'first_visit_at' => now()->subDays(40),
            'last_visit_at' => now()->subDays(2),
            'quiet_at' => now()->addDays(12),
            'lost_at' => now()->addDays(58),
            'rewards_redeemed' => 1,
            ...$values,
        ]);
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the owner each tab\'s count and who came in, newest visit first, by first name and initial', function (): void {
    $regular = ($this->customer)('Salma Tazi', ['last_visit_at' => '2026-10-31 22:00:00', 'rewards_redeemed' => 2]);
    $quiet = ($this->customer)('Youssef Bennani', ['visits' => 8, 'last_visit_at' => now()->subDays(16), 'quiet_at' => now()->subDay()]);
    $lost = ($this->customer)('Karim Alaoui', ['last_visit_at' => now()->subDays(63), 'quiet_at' => now()->subDays(3), 'lost_at' => now()->subDays(3)]);
    $new = ($this->customer)('Imane Rami', ['visits' => 1, 'first_visit_at' => now()->subDays(3), 'last_visit_at' => now()->subDays(3)]);
    ($this->customer)('Nadia Elfassi', [], $this->tenants->a2);
    ($this->customer)('Omar Ziani', [], $this->tenants->b1);

    ($this->open)()->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('business/customers/index')
        ->where('segment', 'all')
        ->where('q', null)
        ->where('counts', ['all' => 4, 'regulars' => 1, 'quiet' => 1, 'lost' => 1, 'new' => 1])
        ->where('customers', [
            // 22:00 UTC yesterday is today in Dubai.
            ['id' => $regular->id, 'name' => 'Salma T.', 'visits' => 3, 'lastVisitDays' => 0, 'rewardsRedeemed' => 2, 'status' => 'active'],
            ['id' => $new->id, 'name' => 'Imane R.', 'visits' => 1, 'lastVisitDays' => 3, 'rewardsRedeemed' => 1, 'status' => 'active'],
            ['id' => $quiet->id, 'name' => 'Youssef B.', 'visits' => 8, 'lastVisitDays' => 16, 'rewardsRedeemed' => 1, 'status' => 'quiet'],
            ['id' => $lost->id, 'name' => 'Karim A.', 'visits' => 3, 'lastVisitDays' => 63, 'rewardsRedeemed' => 1, 'status' => 'lost'],
        ])
        ->where('page', ['current' => 1, 'last' => 1, 'from' => 1, 'to' => 4, 'total' => 4])
        ->where('nav.customers', true));
});

it('shows one tab at a time, the newest first visit first in New', function (): void {
    $older = ($this->customer)('Imane Rami', ['first_visit_at' => now()->subDays(20)]);
    $newer = ($this->customer)('Salma Tazi', ['first_visit_at' => now()->subDays(5), 'last_visit_at' => now()->subDays(10)]);
    ($this->customer)('Karim Alaoui');
    $quiet = ($this->customer)('Youssef Bennani', ['quiet_at' => now()->subDay()]);
    $ids = fn (string $segment): array => array_column(($this->open)(['segment' => $segment])->viewData('page')['props']['customers'], 'id');

    // The same last visit: the newest customer first.
    $twin = ($this->customer)('Omar Ziani', ['quiet_at' => now()->subDay()]);

    expect($ids('new'))->toBe([$newer->id, $older->id])
        ->and($ids('quiet'))->toBe([$twin->id, $quiet->id]);
});

it('finds customers by the start of their first name, whatever the case, never by their last name', function (): void {
    $salma = ($this->customer)('Salma Tazi');
    ($this->customer)('Youssef Salmi');
    $quietSalma = ($this->customer)('salma Idrissi', ['quiet_at' => now()->subDay()]);

    ($this->open)(['q' => '  SAL '])->assertInertia(fn (Assert $page): Assert => $page
        ->where('q', 'SAL')
        ->where('customers', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all() === [$salma->id, $quietSalma->id])
        // The counts are the business's, whatever was typed.
        ->where('counts.all', 3));

    ($this->open)(['q' => 'sal', 'segment' => 'quiet'])->assertInertia(fn (Assert $page): Assert => $page->where('customers.0.id', $quietSalma->id)->has('customers', 1));
    ($this->open)(['q' => '%'])->assertInertia(fn (Assert $page): Assert => $page->has('customers', 0));
});

it('shows 25 a page', function (): void {
    foreach (range(1, ListCustomers::PER_PAGE + 1) as $day) {
        ($this->customer)('Customer '.$day, ['last_visit_at' => now()->subDays($day)]);
    }

    ($this->open)(['page' => 2])->assertInertia(fn (Assert $page): Assert => $page
        ->has('customers', 1)
        ->where('customers.0.name', 'Customer 2.')
        ->where('page', ['current' => 2, 'last' => 2, 'from' => 26, 'to' => 26, 'total' => 26]));
});

it('shows the last page for a page past it', function (): void {
    foreach (range(1, ListCustomers::PER_PAGE + 1) as $day) {
        ($this->customer)('Customer '.$day, ['last_visit_at' => now()->subDays($day)]);
    }

    ($this->open)(['page' => 5])->assertInertia(fn (Assert $page): Assert => $page
        ->has('customers', 1)
        ->where('page.current', 2));
});

it('refuses a tab it does not know', function (): void {
    ($this->open)(['segment' => 'everyone'])->assertSessionHasErrors('segment');
});

it('keeps the customers from staff, and from an organization admin outside a business', function (): void {
    ($this->open)([], $this->tenants->member(User::factory()->create(), $this->tenants->a1))->assertForbidden();

    $admin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->actingAs($admin)->withSession([SetTenant::SESSION_KEY => 'organization:'.$this->tenants->orgA->id])
        ->get(route('customers.index'))->assertForbidden();
});

it('names the dashboard\'s top customers by first name and initial', function (): void {
    $customer = User::factory()->create(['name' => 'Salma Tazi']);
    $this->tenants->stamp($this->tenants->enroll($customer, $this->tenants->cardA), $this->tenants->a1);

    ($this->as)($this->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page->where('stats.topCustomers.0.name', 'Salma T.'));
});
