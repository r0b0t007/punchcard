<?php

declare(strict_types=1);

use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Events\EnrollmentChanged;
use App\Models\CardEnrollment;
use App\Models\NfcTag;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Providers\AppServiceProvider;
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
| row) and redirects to GET /t/result, which shows this session's last tap.
| Signed in, the tap is stamped at once. Signed out, it waits as a pending
| tap kept in the session; after sign-in, GET /t/claim applies it once. Tap
| ids never appear in a URL, so nobody else can see or claim a tap.
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

    $this->tapUrl = fn (int $counter): string => route('taps.receive', app(FakeTap::class)->build($this->tag->uid, $counter));
    $this->lastTap = fn (): Tap => $this->context->bypass(fn (): Tap => Tap::query()->latest('id')->firstOrFail());
    $this->stamps = fn (): int => $this->context->bypass(fn (): int => (int) StampEvent::query()->sum('qty'));
    $this->statusOf = fn (Tap $tap): ?TapStatus => $this->context->bypass(fn (): ?TapStatus => $tap->fresh()?->status);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('stamps a signed-in customer at once and shows their card, never cached', function (): void {
    $this->actingAs($this->customer)->get(($this->tapUrl)(5))
        ->assertRedirect(route('taps.result'))
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->actingAs($this->customer)->get(route('taps.result'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('tap/stamped')
            ->where('given', 1)
            ->where('rewards', [])
            ->where('card.businessName', 'A1')
            ->where('card.stampsCollected', 1)
            ->where('card.stampsRequired', 10));
});

it('keeps a signed-out tap pending, then stamps it once after signing in or up', function (string $how): void {
    $seen = [];
    Event::listen(EnrollmentChanged::class, function () use (&$seen): void {
        $seen[] = app(TenantContext::class)->businessId();
    });

    $this->get(($this->tapUrl)(5))->assertRedirect(route('taps.result'));
    $tap = ($this->lastTap)();
    $this->get(route('taps.result'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('tap/pending')
            ->where('card.businessName', 'A1')
            ->where('card.stampsCollected', 0));
    expect(($this->stamps)())->toBe(0);

    $signIn = $how === 'sign in'
        ? $this->post(route('login.store'), ['email' => $this->customer->email, 'password' => 'password'])
        : $this->post(route('register.store'), ['name' => 'Salma', 'email' => 'salma@example.com', 'password' => 'password', 'password_confirmation' => 'password']);
    $signIn->assertRedirect(route('taps.claim'));

    $this->get(route('taps.claim'))->assertRedirect(route('taps.result'));
    $this->get(route('taps.claim'))->assertRedirect(route('home'));
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped')->where('given', 1));

    expect(($this->stamps)())->toBe(1)
        ->and(($this->statusOf)($tap))->toBe(TapStatus::Stamped)
        ->and($seen)->toBe([$this->tenants->a1->id]);
})->with(['sign in', 'sign up']);

it('claims a pending tap when its result page is opened after signing in', function (): void {
    $this->get(($this->tapUrl)(5));
    $this->actingAs($this->customer);

    $this->get(route('taps.result'))->assertRedirect(route('taps.claim'));
    $this->get(route('taps.claim'))->assertRedirect(route('taps.result'));

    expect(($this->stamps)())->toBe(1);
});

it('never shows or claims a tap from another session, and has no tap ids in its URLs', function (bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs($this->customer);
    }
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();

    $this->flushSession();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('taps.result'))->assertRedirect(route('home'));
    $this->actingAs($stranger)->get(route('taps.claim'))->assertRedirect(route('home'));
    $this->actingAs($stranger)->get('/t/'.$tap->id)->assertNotFound();

    expect(($this->stamps)())->toBe($signedIn ? 1 : 0)
        ->and($this->context->bypass(fn (): ?int => $tap->fresh()?->user_id))->toBe($signedIn ? $this->customer->id : null);
})->with(['a stamped tap' => true, 'a pending tap' => false]);

it('never claims a tap that belongs to another customer, without a server error', function (): void {
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();
    $other = User::factory()->create();
    $this->context->bypass(fn () => $tap->forceFill(['user_id' => $other->id])->save());

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('home'));

    expect(($this->stamps)())->toBe(0)
        ->and(($this->statusOf)($tap))->toBe(TapStatus::Pending);
});

it('keeps every signed-out tap, so armed stamps are not lost to a second tap', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->addSeconds(60)])->save());
    $this->get(($this->tapUrl)(5));
    $armed = ($this->lastTap)();
    $this->travel(1)->minutes();
    $this->get(($this->tapUrl)(6));
    $second = ($this->lastTap)();

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.result'));

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped')->where('given', 3));
    expect(($this->stamps)())->toBe(3)
        ->and(($this->statusOf)($armed))->toBe(TapStatus::Stamped)
        ->and($this->context->bypass(fn (): ?TapRejection => $second->fresh()?->rejection))->toBe(TapRejection::Cooldown);
});

it('stamps nothing more when a result page is reloaded, and refuses the reused URL', function (): void {
    $url = ($this->tapUrl)(5);
    $this->actingAs($this->customer)->get($url);
    $this->get(route('taps.result'));
    $this->get(route('taps.result'));

    $this->get($url);

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', 'used'));
    expect(($this->stamps)())->toBe(1);
});

// Asia/Dubai is a fixed UTC+4 in every tz database; Morocco's offset is not.
it('shows when the next stamp is possible, in the location\'s time', function (string $now, int $cooldown, array $nextStamp): void {
    Carbon::setTestNow($now);
    $this->context->bypass(function () use ($cooldown): void {
        $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save();
        $this->tenants->cardA->forceFill(['cooldown_min' => $cooldown])->save();
    });
    $this->actingAs($this->customer)->get(($this->tapUrl)(5));
    $this->travel(5)->minutes();

    $this->get(($this->tapUrl)(6));

    expect(($this->lastTap)()->rejection)->toBe(TapRejection::Cooldown);
    $this->get(route('taps.result'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('tap/cooldown')
            ->where('nextStamp', $nextStamp)
            ->where('card.stampsCollected', 1));
})->with([
    'later today' => ['2026-10-05 10:00:00', 20, ['day' => 'today', 'date' => '2026-10-05', 'time' => '14:20']],
    'past midnight' => ['2026-10-05 19:45:00', 20, ['day' => 'tomorrow', 'date' => '2026-10-06', 'time' => '00:05']],
    'a daily card' => ['2026-10-05 10:00:00', 1440, ['day' => 'tomorrow', 'date' => '2026-10-06', 'time' => '14:00']],
    'a two-day card' => ['2026-10-05 10:00:00', 2880, ['day' => 'later', 'date' => '2026-10-07', 'time' => '14:00']],
]);

it('gives a friendly reason when there is no stamp', function (Closure $arrange, string $reason): void {
    $arrange->call($this);

    $this->actingAs($this->customer)->get(($this->tapUrl)(5));
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', $reason)->missing('card'));
})->with([
    'the daily limit' => [function (): void {
        $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['cooldown_min' => 0, 'daily_cap' => 1])->save());
        $this->actingAs($this->customer)->get(($this->tapUrl)(4));
    }, 'limit'],
    'a disabled stamper' => [function (): void {
        $this->context->bypass(fn () => $this->stamper->forceFill(['status' => StamperStatus::Disabled])->save());
    }, 'unavailable'],
    'an inactive card' => [function (): void {
        $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['active' => false])->save());
    }, 'card'],
]);

it('records a URL with arrays or nothing in it as malformed, never a server error', function (string $query): void {
    $this->get('/t?'.$query)->assertRedirect(route('taps.result'));

    expect(($this->lastTap)()->rejection)->toBe(TapRejection::Malformed);
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', 'invalid'));
})->with(['e[]=1&c[]=2', '', 'e=zz&c=yy']);

it('rate limits taps per IP before recording them, whatever X-Forwarded-For says', function (): void {
    config(['punchcard.taps.per_ip_per_minute' => 3]);

    foreach (range(1, 3) as $counter) {
        $this->withHeader('X-Forwarded-For', "198.51.100.{$counter}")->get(($this->tapUrl)($counter))->assertRedirect();
    }

    $this->withHeader('X-Forwarded-For', '198.51.100.9')->get(($this->tapUrl)(4))->assertTooManyRequests();
    expect($this->context->bypass(fn (): int => Tap::query()->count()))->toBe(3)
        ->and(($this->lastTap)()->ip)->toBe('127.0.0.1');
});

it('rate limits an IPv6 client by its /64, so rotating addresses does not help', function (): void {
    config(['punchcard.taps.per_ip_per_minute' => 2]);

    foreach (['2001:db8:1:2::1', '2001:db8:1:2:ffff::2'] as $counter => $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get(($this->tapUrl)($counter + 1))->assertRedirect();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:abcd::3'])->get(($this->tapUrl)(3))->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])->get(($this->tapUrl)(3))->assertRedirect();
    expect(AppServiceProvider::clientKey('203.0.113.7'))->toBe('ip:203.0.113.7');
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

    $this->get(route('taps.result'))
        ->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', 'expired'));
    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.result'));

    expect(($this->statusOf)($tap))->toBe(TapStatus::Expired)
        ->and($this->context->bypass(fn (): bool => CardEnrollment::query()->where('user_id', $this->customer->id)->exists()))->toBeFalse();
});

it('works in Arabic, right to left', function (): void {
    $this->withCookie('locale', 'ar')->get(($this->tapUrl)(5));

    $this->withCookie('locale', 'ar')->get(route('taps.result'))
        ->assertInertia(fn (Assert $page): Assert => $page->component('tap/pending')->where('locale', 'ar')->where('dir', 'rtl'));
});
