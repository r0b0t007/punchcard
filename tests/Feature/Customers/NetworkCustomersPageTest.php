<?php

declare(strict_types=1);

use App\Actions\Customers\SummariseCustomer;
use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\CustomerNote;
use App\Models\Organization;
use App\Models\OrganizationCustomer;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A franchise's customers, for its head office (CHW-365, design 23)
|--------------------------------------------------------------------------
|
| Its org admin, working across the network, sees everyone who came in to
| any of its sites, each once, with the businesses they visit; and one of
| them with every site's history and notes, each naming its business. Read
| only: no stamps, no notes. A franchisee still sees only its own; another
| organization none.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->admin = $this->tenants->admin(User::factory()->create(['name' => 'Hicham Mansouri']), $this->tenants->orgA);
    $this->at = fn (User $user, Organization|Business $tenant) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => $tenant instanceof Organization
        ? SetTenant::organizationChoice($tenant->id) : 'business:'.$tenant->id]);

    // Someone who came in at these businesses, a day apart, summarised as the listener does.
    $this->visitor = function (string $name, array $businesses, mixed $card = null, ?Organization $organization = null): CardEnrollment {
        $enrollment = $this->tenants->enroll(User::factory()->create(['name' => $name]), $card ?? $this->tenants->cardA);

        foreach ($businesses as $day => $business) {
            $this->tenants->stamp($enrollment, $business, ['source' => StampSource::Qr, 'created_at' => Carbon::parse('2026-10-20 10:00:00')->addDays($day)]);
            app(SummariseCustomer::class)->handle($business->id, ($organization ?? $this->tenants->orgA)->id, $enrollment->user_id);
        }

        return $enrollment;
    };
    $this->salma = ($this->visitor)('Salma Tazi', [$this->tenants->a1, $this->tenants->a2]);
    ($this->visitor)('Youssef Bennani', [$this->tenants->a2]);
    ($this->visitor)('Bilal Other', [$this->tenants->b1], $this->tenants->cardB, $this->tenants->orgB);
    $this->network = fn (CardEnrollment $enrollment): OrganizationCustomer => $this->context->bypass(fn () => OrganizationCustomer::query()
        ->where('user_id', $enrollment->user_id)->sole());
    $this->note = fn (Business $business, string $body): CustomerNote => $this->context->bypass(fn () => CustomerNote::query()->create([
        'business_id' => $business->id, 'user_id' => $this->salma->user_id, 'author_id' => $this->admin->id, 'body' => $body,
    ]));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the head office everyone who came in to any of its sites, each once, with the businesses they visit', function (): void {
    ($this->at)($this->admin, $this->tenants->orgA)->get(route('customers.index'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/customers/index')
            ->where('level', 'network')
            ->where('canExport', false)
            ->where('counts.all', 2)
            // Newest visit first: Salma came to A2 on the 21st, Youssef on the 20th.
            ->where('customers.0.name', 'Salma T.')
            ->where('customers.0.visits', 2)
            ->where('customers.0.sites', [$this->tenants->a1->name, $this->tenants->a2->name])
            ->where('customers.1.name', 'Youssef B.')
            ->where('customers.1.sites', [$this->tenants->a2->name])
            ->missing('customers.0.userId')
            ->where('nav.customers', true));

    ($this->at)($this->admin, $this->tenants->orgA)->get(route('customers.index', ['q' => 'Sal']))
        ->assertInertia(fn (Assert $page): Assert => $page->has('customers', 1)->where('customers.0.name', 'Salma T.'));
});

it('pages the network\'s customers 25 at a time', function (): void {
    $this->context->bypass(fn () => collect(range(1, 25))->each(fn (int $n) => OrganizationCustomer::query()->create([
        'organization_id' => $this->tenants->orgA->id, 'user_id' => User::factory()->create()->id, 'visits' => 1,
        'first_visit_at' => now()->subDays(60 + $n), 'last_visit_at' => now()->subDays(60 + $n), 'quiet_at' => now()->subDays(40), 'lost_at' => now()->subDays(1),
    ])));

    ($this->at)($this->admin, $this->tenants->orgA)->get(route('customers.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('page.total', 27)->where('page.current', 2)->has('customers', 2));
});

it('shows the head office one customer, read only, with every site\'s history and notes naming their business', function (): void {
    $this->context->bypass(fn () => Reward::factory()->for($this->salma, 'enrollment')->create()->forceFill([
        'status' => RewardStatus::Redeemed, 'redeemed_at' => '2026-10-25 10:00:00',
        'redeemed_business_id' => $this->tenants->a2->id, 'redeemed_location_id' => $this->tenants->locationOf($this->tenants->a2)->id,
    ])->save());
    ($this->note)($this->tenants->a1, 'Prefers oat milk.');
    ($this->note)($this->tenants->a2, 'Comes on Fridays.');
    $customer = ($this->network)($this->salma);

    ($this->at)($this->admin, $this->tenants->orgA)->get(route('customers.show', $customer->id))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/customers/show')
            ->where('level', 'network')
            ->where('customer.name', 'Salma T.')
            ->where('customer.visits', 2)
            ->where('customer.sites', [$this->tenants->a1->name, $this->tenants->a2->name])
            ->where('history.0.kind', 'redeemed')
            ->where('history.0.business', $this->tenants->a2->name)
            ->where('history.1.business', $this->tenants->a2->name)
            ->where('history.2.business', $this->tenants->a1->name)
            ->where('notes.0.body', 'Comes on Fridays.')
            ->where('notes.0.business', $this->tenants->a2->name)
            ->where('notes.1.business', $this->tenants->a1->name)
            ->where('locations', [])
            ->where('correctionLocations', []));
});

it('never lets the head office stamp or keep notes', function (): void {
    $customer = ($this->network)($this->salma);

    ($this->at)($this->admin, $this->tenants->orgA)->post(route('customers.stamps.store', $customer->id), [])->assertNotFound();
    ($this->at)($this->admin, $this->tenants->orgA)->post(route('customers.notes.store', $customer->id), ['body' => 'From HQ.'])->assertNotFound();

    expect($this->context->bypass(fn () => CustomerNote::query()->count()))->toBe(0);
});

it('keeps a franchisee to its own customers, and another organization out', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $otherAdmin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgB);
    $salma = ($this->network)($this->salma);

    // A1's owner: A1's list, unchanged; the network's customer is never found there.
    ($this->at)($owner, $this->tenants->a1)->get(route('customers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('level', 'business')->where('counts.all', 1)->where('customers.0.name', 'Salma T.'));

    // Org B's head office: its own customer only; org A's is not found.
    ($this->at)($otherAdmin, $this->tenants->orgB)->get(route('customers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('counts.all', 1)->where('customers.0.name', 'Bilal O.'));
    ($this->at)($otherAdmin, $this->tenants->orgB)->get(route('customers.show', $salma->id))->assertNotFound();
});
