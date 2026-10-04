<?php

declare(strict_types=1);

use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Events\EnrollmentChanged;
use App\Models\CardEnrollment;
use App\Models\NfcTag;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The /t tap endpoint (CHW-25)
|--------------------------------------------------------------------------
|
| GET /t receives the tap (rate limited first: every request writes a tap
| row) and redirects to its result page, GET /t/{tap}. Signed in, the tap is
| stamped at once. Signed out, it waits as a pending tap whose id lives only
| in the session; after sign-in, GET /t/claim applies it once. Nobody else,
| by session or by guessing an id, can see or claim it.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 10:00:00');
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id));
    $this->customer = User::factory()->create();

    $this->tapUrl = function (int $counter): string {
        $url = app(FakeTap::class)->build($this->tag->uid, $counter);

        return route('taps.receive', $url);
    };
    $this->lastTap = fn (): Tap => $this->context->bypass(fn (): Tap => Tap::query()->latest('id')->firstOrFail());
    $this->stamps = fn (): int => $this->context->bypass(fn (): int => StampEvent::query()->count());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('stamps a signed-in customer at once and shows their card', function (): void {
    $response = $this->actingAs($this->customer)->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();

    $response->assertRedirect(route('taps.show', $tap->id));
    $this->actingAs($this->customer)->get(route('taps.show', $tap->id))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('tap/stamped')
            ->where('given', 1)
            ->where('card.businessName', 'A1')
            ->where('card.stampsCollected', 1)
            ->where('card.stampsRequired', 10));
});

it('keeps a signed-out tap pending in the session, then stamps it once after sign-in', function (): void {
    $this->get(($this->tapUrl)(5))->assertRedirect();
    $tap = ($this->lastTap)();

    $this->get(route('taps.show', $tap->id))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('tap/pending')
            ->where('card.businessName', 'A1')
            ->where('card.stampsCollected', 0)
            ->missing('tap'));
    expect(session('url.intended'))->toBe(route('taps.claim'))
        ->and(($this->stamps)())->toBe(0);

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.show', $tap->id));
    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('home'));
    $this->actingAs($this->customer)->get(route('taps.show', $tap->id))->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped'));

    expect(($this->stamps)())->toBe(1)
        ->and($this->context->bypass(fn (): ?TapStatus => $tap->fresh()?->status))->toBe(TapStatus::Stamped);
});

it('never lets another session or a guessed id see or claim a pending tap', function (): void {
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();
    $this->flushSession();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('taps.show', $tap->id))->assertNotFound();
    $this->actingAs($stranger)->get(route('taps.claim'))->assertRedirect(route('home'));

    expect(($this->stamps)())->toBe(0)
        ->and($this->context->bypass(fn (): ?TapStatus => $tap->fresh()?->status))->toBe(TapStatus::Pending);
});

it('stamps nothing more when a result page is reloaded, and refuses the reused URL', function (): void {
    $url = ($this->tapUrl)(5);
    $this->actingAs($this->customer)->get($url);
    $tap = ($this->lastTap)();
    $this->actingAs($this->customer)->get(route('taps.show', $tap->id));

    $this->actingAs($this->customer)->get($url);
    $replay = ($this->lastTap)();

    $this->actingAs($this->customer)->get(route('taps.show', $replay->id))
        ->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', 'used'));
    expect(($this->stamps)())->toBe(1);
});

it('shows when the next stamp is possible, in the location\'s time', function (): void {
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Africa/Casablanca'])->save());
    $this->actingAs($this->customer)->get(($this->tapUrl)(5));
    $this->travel(5)->minutes();

    $this->actingAs($this->customer)->get(($this->tapUrl)(6));
    $tap = ($this->lastTap)();

    expect($tap->rejection)->toBe(TapRejection::Cooldown);
    $this->actingAs($this->customer)->get(route('taps.show', $tap->id))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('tap/cooldown')
            ->where('availableAt', '11:20')
            ->where('card.stampsCollected', 1));
});

it('records a URL with arrays or nothing in it as malformed, never a server error', function (string $query): void {
    $this->get('/t?'.$query)->assertRedirect();

    expect(($this->lastTap)()->rejection)->toBe(TapRejection::Malformed);
})->with(['e[]=1&c[]=2', '', 'e=zz&c=yy']);

it('rate limits taps per IP before recording them', function (): void {
    config(['punchcard.taps.per_ip_per_minute' => 3]);

    foreach (range(1, 3) as $counter) {
        $this->get(($this->tapUrl)($counter))->assertRedirect();
    }

    $this->get(($this->tapUrl)(4))->assertTooManyRequests();
    expect($this->context->bypass(fn (): int => Tap::query()->count()))->toBe(3);
});

it('rate limits taps per customer, whatever their IP', function (): void {
    config(['punchcard.taps.per_user_per_minute' => 2]);

    foreach (range(1, 2) as $counter) {
        $this->actingAs($this->customer)->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$counter}"])->get(($this->tapUrl)($counter))->assertRedirect();
    }

    $this->actingAs($this->customer)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(($this->tapUrl)(3))->assertTooManyRequests();
});

it('hands the café\'s tenant to the queued listeners of a stamp', function (): void {
    $seen = [];
    Event::listen(EnrollmentChanged::class, function () use (&$seen): void {
        $seen[] = app(TenantContext::class)->businessId();
    });

    $this->actingAs($this->customer)->get(($this->tapUrl)(5));

    expect($seen)->toBe([$this->tenants->a1->id]);
});

it('tells a customer their signed-out tap expired', function (): void {
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();
    $this->travel(31)->minutes();

    $this->get(route('taps.show', $tap->id))
        ->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', 'expired'));
    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.show', $tap->id));

    expect($this->context->bypass(fn (): ?TapStatus => $tap->fresh()?->status))->toBe(TapStatus::Expired)
        ->and($this->context->bypass(fn (): bool => CardEnrollment::query()->where('user_id', $this->customer->id)->exists()))->toBeFalse();
});

it('works in Arabic, right to left', function (): void {
    $this->withCookie('locale', 'ar')->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();

    $this->withCookie('locale', 'ar')->get(route('taps.show', $tap->id))
        ->assertInertia(fn (Assert $page): Assert => $page->component('tap/pending')->where('locale', 'ar')->where('dir', 'rtl'));
});
