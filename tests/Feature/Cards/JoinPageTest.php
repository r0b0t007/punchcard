<?php

declare(strict_types=1);

use App\Enums\BusinessStatus;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Http\ClientAddress;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The join page (CHW-31): the QR stand's link
|--------------------------------------------------------------------------
|
| /j/{slug} shows a business's card to anyone. Signed in, a customer adds
| it to their cards; signed out, they sign in or register and come back
| here. Joining gives no stamp: only a tap or a staff scan proves presence.
| A suspended or closed business has no join page; a pending one does, as
| it takes taps (CHW-22).
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->business = $this->tenants->b1;
    $this->customer = User::factory()->create();
    $this->page = fn () => $this->get(route('join.show', $this->business->slug));
    $this->enrollments = fn (): int => $this->context->bypass(fn (): int => CardEnrollment::query()->where('user_id', $this->customer->id)->count());
});

it('shows the business\'s card to a visitor, changing nothing by being viewed', function (): void {
    ($this->page)()->assertOk()
        ->assertInertia(fn ($page) => $page->component('join/show')
            ->where('card.businessName', 'B1')
            ->where('available', true)
            ->where('joined', false));

    expect(session('url.intended'))->toBeNull();
});

it('brings a visitor back here after they register or sign in from it', function (string $via, string $to): void {
    $this->get(route($via, $this->business->slug))->assertRedirect(route($to));

    expect(session('url.intended'))->toBe(route('join.show', $this->business->slug));
})->with([
    'register' => ['join.register', 'register'],
    'sign in' => ['join.login', 'login'],
]);

it('adds the card to a signed-in customer\'s cards once, with no stamp', function (): void {
    $this->actingAs($this->customer);

    $this->post(route('join.store', $this->business->slug))->assertRedirect(route('join.show', $this->business->slug));
    $this->post(route('join.store', $this->business->slug));

    expect(($this->enrollments)())->toBe(1)
        ->and($this->context->bypass(fn (): int => StampEvent::query()->count()))->toBe(0);
    ($this->page)()->assertInertia(fn ($page) => $page->where('joined', true));
});

it('works for a business still pending verification', function (): void {
    $this->context->bypass(fn () => $this->business->forceFill(['status' => BusinessStatus::Pending, 'verified_at' => null])->save());

    ($this->page)()->assertOk();
    $this->actingAs($this->customer)->post(route('join.store', $this->business->slug));

    expect(($this->enrollments)())->toBe(1);
});

it('has no join page for an unknown, suspended or closed business', function (string $case): void {
    $slug = match ($case) {
        'unknown' => 'nowhere',
        'suspended' => $this->context->bypass(fn (): string => tap($this->business)->forceFill(['status' => BusinessStatus::Suspended])->save() ? $this->business->slug : ''),
        'closed' => $this->context->bypass(fn (): string => tap($this->business)->forceFill(['archived_at' => now()])->save() ? $this->business->slug : ''),
    };

    $this->get(route('join.show', $slug))->assertNotFound();
    $this->actingAs($this->customer)->post(route('join.store', $slug))->assertNotFound();
})->with(['unknown', 'suspended', 'closed']);

it('says the card is not available yet when the business honours no active card', function (): void {
    $this->context->bypass(fn () => LoyaltyCard::query()->whereKey($this->tenants->cardB->id)->update(['active' => false]));

    ($this->page)()->assertOk()->assertInertia(fn ($page) => $page->where('available', false));
    $this->actingAs($this->customer)->post(route('join.store', $this->business->slug))->assertRedirect(route('join.show', $this->business->slug));

    expect(($this->enrollments)())->toBe(0);
});

it('limits how often one customer joins', function (): void {
    $this->actingAs($this->customer);

    foreach (range(1, 10) as $attempt) {
        $this->post(route('join.store', $this->business->slug));
    }

    $this->post(route('join.store', $this->business->slug))->assertTooManyRequests();
});

it('shows another business\'s card, never mixing the two', function (): void {
    $this->get(route('join.show', $this->tenants->a1->slug))
        ->assertInertia(fn ($page) => $page->where('card.businessName', 'A1')->where('card.cardName', 'A card'));
});

it('leaves a tap waiting to be claimed its sign-in redirect, whatever host it was set on', function (): void {
    $claim = 'https://stamp.example'.parse_url(route('taps.claim'), PHP_URL_PATH);
    $this->withSession(['url.intended' => $claim]);

    $this->get(route('join.register', $this->business->slug))->assertRedirect(route('register'));

    expect(session('url.intended'))->toBe($claim);
});

it('shows the card the customer holds there, as a tap would use it', function (): void {
    $this->context->bypass(function (): void {
        $second = LoyaltyCard::factory()->for($this->tenants->orgB)->create(['name' => 'Second card']);
        $second->businesses()->attach($this->business->id);
        CardEnrollment::factory()->for($second, 'card')->for($this->customer)->create();
    });

    $this->actingAs($this->customer);
    ($this->page)()->assertInertia(fn ($page) => $page->where('joined', true)->where('card.cardName', 'Second card'));
});

it('offers a customer holding a paused card the card the business runs now (CHW-148)', function (): void {
    $this->context->bypass(function (): void {
        $old = LoyaltyCard::factory()->for($this->tenants->orgB)->create(['name' => 'Old card', 'active' => false]);
        $old->businesses()->attach($this->business->id);
        CardEnrollment::factory()->for($old, 'card')->for($this->customer)->create();
    });

    $this->actingAs($this->customer);
    ($this->page)()->assertInertia(fn ($page) => $page->where('available', true)->where('joined', false)->where('card.cardName', 'B card'));
    $this->post(route('join.store', $this->business->slug));

    expect(($this->enrollments)())->toBe(2);
    ($this->page)()->assertInertia(fn ($page) => $page->where('joined', true)->where('card.cardName', 'B card'));
});

it('brings a visitor back to this page, not one left behind earlier', function (): void {
    $this->withSession(['url.intended' => route('dashboard')]);

    $this->get(route('join.login', $this->business->slug))->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('join.show', $this->business->slug));
});

it('limits join page views per browser session, and far more loosely per address (shared carrier IPs)', function (): void {
    $request = Request::create(route('join.show', $this->business->slug), server: ['REMOTE_ADDR' => '203.0.113.7']);
    $request->setLaravelSession($session = app('session')->driver());
    $session->start();

    $limits = RateLimiter::limiter('join-page')($request);

    expect(array_map(fn (Limit $limit): array => [$limit->maxAttempts, $limit->decaySeconds, $limit->key], $limits))->toBe([
        [60, 60, 'join-page:session:'.$session->getId()],
        [600, 60, 'join-page:address:'.ClientAddress::rateLimitKey('203.0.113.7')],
    ]);
});

it('throttles the join page through its limiter', function (): void {
    RateLimiter::for('join-page', fn (): Limit => Limit::perMinute(2)->by('join-page:test'));

    ($this->page)();
    ($this->page)();

    ($this->page)()->assertTooManyRequests();
});
