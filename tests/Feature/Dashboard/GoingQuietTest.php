<?php

declare(strict_types=1);

use App\Actions\Dashboard\DescribeGoingQuiet;
use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Going quiet on the dashboard (CHW-45, B3, design 16)
|--------------------------------------------------------------------------
|
| The owner sees who is going quiet at the business now, by the Customers
| page's rule (late for their usual visit, not lost yet): how many, and the
| 5 with the most visits, to nudge with a campaign. The business's
| customers, whatever location is picked; never a sister franchisee's.
|
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 11:00:00', 'UTC'));
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    // A fixed offset (UTC+4): 22:00 UTC is already the next day there.
    $this->context->bypass(fn () => Location::query()->update(['timezone' => 'Asia/Dubai']));

    // Going quiet unless told otherwise: 5 visits, every 4 days, the last 16 days ago; quiet since 8 days.
    $this->customer = fn (string $name, array $values = [], ?Business $business = null): BusinessCustomer => $this->context->bypass(function () use ($name, $values, $business): BusinessCustomer {
        $business ??= $this->tenants->a1;

        return BusinessCustomer::query()->create([
            'organization_id' => $business->organization_id,
            'business_id' => $business->id,
            'user_id' => User::factory()->create(['name' => $name])->id,
            'visits' => 5,
            'first_visit_at' => now()->subDays(40),
            'last_visit_at' => now()->subDays(16),
            'usual_gap_days' => 4,
            'quiet_at' => now()->subDays(8),
            'lost_at' => now()->addDays(44),
            'rewards_redeemed' => 0,
            ...$values,
        ]);
    });
    $this->goingQuiet = function (?Business $business = null): array {
        $business ??= $this->tenants->a1;
        $this->context->set($this->context->bypass(fn () => $business->organization()->firstOrFail()), $business, orgAdmin: true);

        return app(DescribeGoingQuiet::class)->handle($business, CarbonImmutable::now());
    };
    $this->owner = fn (): User => $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->dashboard = fn (User $user, array $query = []) => $this->actingAs($user)
        ->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->a1->id])
        ->get(route('dashboard', $query));
});

it('lists the 5 going quiet with the most visits, then the latest visit, and counts them all', function (): void {
    $salma = ($this->customer)('Salma Tazi', ['visits' => 23]);
    $omar = ($this->customer)('Omar Ziani', ['visits' => 17, 'last_visit_at' => now()->subDays(15)]);
    $nadia = ($this->customer)('Nadia Ennaji', ['visits' => 17, 'last_visit_at' => now()->subDays(20)]);
    $hamza = ($this->customer)('Hamza Kettani', ['visits' => 6]);
    // A first-time customer: no usual gap yet.
    $leila = ($this->customer)('Leila Mansouri', ['visits' => 1, 'usual_gap_days' => null, 'last_visit_at' => now()->subDays(14)->subHour()]);
    ($this->customer)('Karim Alaoui', ['visits' => 1, 'usual_gap_days' => null]);

    expect(($this->goingQuiet)())->toBe([
        'count' => 6,
        'customers' => [
            ['id' => $salma->id, 'name' => 'Salma T.', 'visits' => 23, 'usualGapDays' => 4, 'lastVisitDays' => 16],
            ['id' => $omar->id, 'name' => 'Omar Z.', 'visits' => 17, 'usualGapDays' => 4, 'lastVisitDays' => 15],
            ['id' => $nadia->id, 'name' => 'Nadia E.', 'visits' => 17, 'usualGapDays' => 4, 'lastVisitDays' => 20],
            ['id' => $hamza->id, 'name' => 'Hamza K.', 'visits' => 6, 'usualGapDays' => 4, 'lastVisitDays' => 16],
            ['id' => $leila->id, 'name' => 'Leila M.', 'visits' => 1, 'usualGapDays' => null, 'lastVisitDays' => 14],
        ],
    ]);
});

it('counts the days since the last visit in the business\'s time', function (): void {
    // 9 Oct 21:00 UTC is 10 Oct 01:00 in Dubai: yesterday there, two days ago in UTC.
    ($this->customer)('Salma Tazi', ['last_visit_at' => '2026-10-09 21:00:00']);

    expect(($this->goingQuiet)()['customers'][0]['lastVisitDays'])->toBe(1);
});

it('takes those going quiet now: from the moment they are, until they are lost', function (): void {
    $this->freezeSecond();
    $justQuiet = ($this->customer)('Salma Tazi', ['quiet_at' => now()]);
    ($this->customer)('Omar Ziani', ['quiet_at' => now()->addSecond()]);
    ($this->customer)('Nadia Ennaji', ['quiet_at' => now()->subDays(50), 'lost_at' => now()]);
    $almostLost = ($this->customer)('Hamza Kettani', ['quiet_at' => now()->subDays(50), 'lost_at' => now()->addSecond()]);

    expect(array_column(($this->goingQuiet)()['customers'], 'id'))->toEqualCanonicalizing([$justQuiet->id, $almostLost->id]);
});

it('keeps to the business\'s own customers, never a sister franchisee\'s', function (): void {
    $here = ($this->customer)('Salma Tazi');
    ($this->customer)('Omar Ziani', business: $this->tenants->a2);

    expect(($this->goingQuiet)())->toMatchArray(['count' => 1])
        ->and(($this->goingQuiet)()['customers'][0]['id'])->toBe($here->id)
        ->and(($this->goingQuiet)($this->tenants->a2)['count'])->toBe(1);
});

it('sends the whole business\'s apart from the figures: picking a location reloads the figures only', function (): void {
    $salma = ($this->customer)('Salma Tazi');
    $other = $this->context->bypass(fn (): Location => Location::factory()->create(['business_id' => $this->tenants->a1->id]));

    ($this->dashboard)(($this->owner)(), ['location' => $other->id])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('stats.location', $other->id)
            ->where('goingQuiet.count', 1)
            ->where('goingQuiet.customers.0.id', $salma->id)
            ->reloadOnly('stats', fn (Assert $reload): Assert => $reload->has('stats')->missing('goingQuiet')));
});

it('shows nobody when nobody is going quiet', function (): void {
    ($this->customer)('Salma Tazi', ['quiet_at' => now()->addDay()]);

    expect(($this->goingQuiet)())->toBe(['count' => 0, 'customers' => []]);
});

it('gives the owner the panel on the dashboard, never staff', function (): void {
    $salma = ($this->customer)('Salma Tazi');

    ($this->dashboard)(($this->owner)())
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('goingQuiet.count', 1)
            ->where('goingQuiet.customers.0.id', $salma->id));
    // Staff have the counter, not the dashboard.
    ($this->dashboard)($this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff))->assertRedirect(route('counter.show'));
});
