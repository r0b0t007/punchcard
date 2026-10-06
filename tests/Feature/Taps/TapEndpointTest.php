<?php

declare(strict_types=1);

use App\Actions\Taps\ExpirePendingTaps;
use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Events\EnrollmentChanged;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\NfcTag;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Taps\TapSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
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
    // Asia/Dubai: a fixed UTC+4 in every tz database.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
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
            ->where('stampedAt', ['day' => 'today', 'date' => '2026-10-05', 'time' => '14:00'])
            ->where('stampsBefore', 0)
            ->where('fresh', true)
            ->where('card.businessName', 'A1')
            ->where('card.locationName', 'A1 site')
            ->where('card.stampsCollected', 1)
            ->where('card.stampsRequired', 10));

    // Coming back to it is not a new stamp: no landing again, and the day shows.
    $this->travel(1)->days();
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('fresh', false)
        ->where('stampedAt.day', 'other'));
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

    $this->get(route('taps.claim'))->assertRedirect(route('taps.result'))->assertHeader('Cache-Control', 'no-store, private');
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
            ->where('stampedMinutesAgo', 5)
            ->where('card.stampsCollected', 1));

    // Measured from now, so coming back later says how long ago it really was.
    $this->travel(10)->minutes();
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->where('stampedMinutesAgo', 15));
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

it('reports a pending tap that fails to apply and keeps it, without holding back the newer ones', function (): void {
    Exceptions::fake();
    $this->get(($this->tapUrl)(5));
    $first = ($this->lastTap)();
    $this->travel(1)->minutes();
    $this->get(($this->tapUrl)(6));
    $second = ($this->lastTap)();
    $database = new stdClass;
    $database->down = true;
    Tap::saving(function (Tap $tap) use ($first, $database): void {
        if ($database->down && $tap->id === $first->id) {
            throw new RuntimeException('The database went away.');
        }
    });

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.result'));
    Exceptions::assertReported(RuntimeException::class);
    expect(($this->statusOf)($second))->toBe(TapStatus::Stamped)
        ->and(($this->statusOf)($first))->toBe(TapStatus::Pending);

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped')->where('given', 1));
    $database->down = false;
    $this->get(route('taps.claim'))->assertRedirect(route('taps.result'));
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped')->where('given', 1));

    expect($this->context->bypass(fn (): ?TapRejection => $first->fresh()?->rejection))->toBe(TapRejection::Cooldown)
        ->and(($this->stamps)())->toBe(1);
});

it('says the next stamp is possible now once the cooldown has passed', function (): void {
    $this->actingAs($this->customer)->get(($this->tapUrl)(5));
    $this->travel(5)->minutes();
    $this->get(($this->tapUrl)(6));
    $this->travel(1)->days();

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/cooldown')->where('nextStamp.day', 'now'));
});

it('shows the card as the stamp left it, not as later stamps changed it', function (): void {
    $this->actingAs($this->customer)->get(($this->tapUrl)(5));
    $this->context->bypass(fn () => CardEnrollment::query()->where('user_id', $this->customer->id)->firstOrFail()->forceFill(['current_stamps' => 7])->save());

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped')->where('given', 1)->where('card.stampsCollected', 1));
});

it('shows the card the stamp was refused on, not the one a first tap here would get', function (): void {
    $newer = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create(['name' => 'Newer card']);
        $card->businesses()->attach($this->tenants->a1->id);

        return $card;
    });
    $this->tenants->enroll($this->customer, $newer);
    $this->actingAs($this->customer)->get(($this->tapUrl)(5));
    $this->travel(5)->minutes();

    $this->get(($this->tapUrl)(6));

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/cooldown')->where('card.cardName', 'Newer card')->where('card.stampsCollected', 1));
});

it('retries a signed-in tap whose stamp failed to apply, armed stamps included', function (): void {
    Exceptions::fake();
    $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->addSeconds(60)])->save());
    $database = new stdClass;
    $database->down = true;
    Tap::saving(function (Tap $tap) use ($database): void {
        if ($database->down && $tap->status === TapStatus::Stamped) {
            throw new RuntimeException('The database went away.');
        }
    });

    $this->actingAs($this->customer)->get(($this->tapUrl)(5))->assertRedirect(route('taps.result'));
    Exceptions::assertReported(RuntimeException::class);
    $this->get(route('taps.result'))->assertRedirect(route('taps.claim'))->assertHeader('Cache-Control', 'no-store, private');
    $this->get(route('taps.claim'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', 'retry'));

    $database->down = false;
    $this->get(route('taps.claim'))->assertRedirect(route('taps.result'));
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped')->where('given', 3));
    expect(($this->stamps)())->toBe(3);
});

it('shows a refused card as the refusal found it, not as later stamps changed it', function (): void {
    $this->actingAs($this->customer)->get(($this->tapUrl)(5));
    $this->travel(5)->minutes();
    $this->get(($this->tapUrl)(6));
    $this->context->bypass(fn () => CardEnrollment::query()->where('user_id', $this->customer->id)->firstOrFail()->forceFill(['current_stamps' => 7])->save());

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/cooldown')->where('card.stampsCollected', 1));
});

it('does not invite a signed-out customer to sign up for a switched-off card', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['active' => false])->save());

    $this->get(($this->tapUrl)(5));

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', 'card'));
});

it('retries a failed stamp when the customer reloads the tap URL', function (): void {
    Exceptions::fake();
    $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->addSeconds(60)])->save());
    $database = new stdClass;
    $database->down = true;
    Tap::saving(function (Tap $tap) use ($database): void {
        if ($database->down && $tap->status === TapStatus::Stamped) {
            throw new RuntimeException('The database went away.');
        }
    });
    $url = ($this->tapUrl)(5);

    $this->actingAs($this->customer)->get($url)->assertRedirect(route('taps.result'));
    $database->down = false;
    $this->get($url)->assertRedirect(route('taps.result'));

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped')->where('given', 3));
    expect(($this->stamps)())->toBe(3);
});

it('shows the tap just made, not an older pending tap that expired', function (): void {
    $this->get(($this->tapUrl)(5));
    $this->travel(31)->minutes();
    $this->tenants->stamp($this->tenants->enroll($this->customer, $this->tenants->cardA), $this->tenants->a1);

    $this->actingAs($this->customer)->get(($this->tapUrl)(6));

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/cooldown'));
});

it('never caches the sign-in redirect of a signed-out claim', function (): void {
    $this->get(route('taps.claim'))->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
});

it('keeps showing a tap waiting for sign-in when its URL is opened again', function (): void {
    $url = ($this->tapUrl)(5);
    $this->get($url);

    $this->get($url)->assertRedirect(route('taps.result'));

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/pending'));
    expect(($this->lastTap)()->rejection)->toBe(TapRejection::Replay);
});

it('shows a card the stamp completed as full, next to its reward', function (): void {
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->context->bypass(fn () => $enrollment->forceFill(['current_stamps' => 9, 'lifetime_stamps' => 9])->save());

    $this->actingAs($this->customer)->get(($this->tapUrl)(5));

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('tap/stamped')
        ->where('card.stampsCollected', 10)
        ->where('stampsBefore', 9)
        ->has('rewards', 1));
    expect(($this->lastTap)()->card_stamps)->toBe(0);
});

it('takes one tap request at a time per session', function (string $route): void {
    expect(Route::getRoutes()->getByName($route)?->locksFor())->toBe(10);
})->with(['taps.receive', 'taps.claim', 'taps.result']);

it('says there are too many taps in the customer\'s language', function (): void {
    $this->withCookie('locale', 'ar')->get(route('taps.busy'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('tap/refused')
            ->where('reason', 'busy')
            ->where('locale', 'ar')
            ->where('dir', 'rtl')
            ->has('translations'));
});

it('shows what became of a failed tap when its URL is reloaded, not just that the URL was used', function (): void {
    Exceptions::fake();
    $database = new stdClass;
    $database->down = true;
    Tap::saving(function (Tap $tap) use ($database): void {
        if ($database->down && $tap->status === TapStatus::Stamped) {
            throw new RuntimeException('The database went away.');
        }
    });
    $url = ($this->tapUrl)(5);
    $this->actingAs($this->customer)->get($url);
    $database->down = false;
    $this->travel(31)->minutes();

    $this->get($url);

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', 'expired'));
});

it('sends only a signed-out customer back to the claim after signing in', function (): void {
    Exceptions::fake();
    Tap::saving(function (Tap $tap): void {
        if ($tap->status === TapStatus::Stamped) {
            throw new RuntimeException('The database went away.');
        }
    });

    $this->actingAs($this->customer)->get(($this->tapUrl)(5));

    expect(session('url.intended'))->toBeNull()
        ->and(($this->lastTap)()->status)->toBe(TapStatus::Pending);
});

it('trusts whatever proxy connects when TRUSTED_PROXIES is "*"', function (): void {
    putenv('TRUSTED_PROXIES=*');
    $config = require config_path('punchcard.php');
    putenv('TRUSTED_PROXIES');
    config(['punchcard.trusted_proxies' => $config['trusted_proxies']]);
    (fn () => $this->trustConfiguredProxies())->call(app()->getProvider(AppServiceProvider::class));

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])->withHeader('X-Forwarded-For', '203.0.113.50')->get(($this->tapUrl)(5));

    expect($config['trusted_proxies'])->toBe('*')
        ->and(($this->lastTap)()->ip)->toBe('203.0.113.50');
    TrustProxies::flushState();
});

it('says how long ago the last stamp before the tap landed, not a stamp given since or one outside the cooldown', function (): void {
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->tenants->stamp($enrollment, $this->tenants->a1, ['created_at' => now()->subDays(3)]);
    $this->get(($this->tapUrl)(5));
    $this->travel(3)->minutes();
    $this->tenants->stamp($enrollment, $this->tenants->a1);
    $this->travel(7)->minutes();

    $this->actingAs($this->customer)->get(route('taps.claim'));

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('tap/cooldown')
        ->where('stampedMinutesAgo', null));
});

it('keeps a signed-out tap when another request writes back an older session', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->addSeconds(60)])->save());
    $this->get(route('home'));
    expect(session('taps.token'))->toBeString();
    $before = session()->all();

    $this->get(($this->tapUrl)(5));
    // A request that loaded the session before the tap saves it after: the session payload goes back.
    session()->flush();
    session()->put($before);

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.result'));
    expect(($this->stamps)())->toBe(3);
});

it('keeps only a hash of the session\'s claim token on the tap', function (): void {
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();
    $token = session('taps.token');

    expect($token)->toBeString()
        ->and($tap->claim_token_hash)->toBe(hash('sha256', (string) $token))
        ->and($this->context->bypass(fn (): bool => Tap::query()->where('claim_token_hash', $token)->exists()))->toBeFalse();
});

it('keeps at most five signed-out taps waiting, the newest, and the rest in the tap log', function (): void {
    foreach (range(1, 6) as $counter) {
        $this->get(($this->tapUrl)($counter));
    }
    $oldest = $this->context->bypass(fn (): Tap => Tap::query()->oldest('id')->firstOrFail());

    expect(app(TapSession::class)->pendingTaps()->modelKeys())->toHaveCount(5)->not->toContain($oldest->id)
        ->and($oldest->claim_token_hash)->toBeNull()
        ->and($oldest->status)->toBe(TapStatus::Pending);
});

it('stops claiming a signed-out tap once the session is ended', function (): void {
    $this->get(($this->tapUrl)(5));

    session()->invalidate();

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('home'));
    expect(($this->stamps)())->toBe(0);
});

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

    $this->withHeader('X-Forwarded-For', '198.51.100.9')->get(($this->tapUrl)(4))
        ->assertRedirect(route('taps.busy'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Retry-After');
    expect($this->context->bypass(fn (): int => Tap::query()->count()))->toBe(3)
        ->and(($this->lastTap)()->ip)->toBe('127.0.0.1');
});

it('rate limits an IPv6 client by its /64, so rotating addresses does not help', function (): void {
    config(['punchcard.taps.per_ip_per_minute' => 2]);

    foreach (['2001:db8:1:2::1', '2001:db8:1:2:ffff::2'] as $counter => $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get(($this->tapUrl)($counter + 1))->assertRedirect();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:abcd::3'])->get(($this->tapUrl)(3))->assertRedirect(route('taps.busy'));
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])->get(($this->tapUrl)(3))->assertRedirect();
});

it('rate limits taps per customer, whatever their IP', function (): void {
    config(['punchcard.taps.per_user_per_minute' => 2]);

    foreach (range(1, 2) as $counter) {
        $this->actingAs($this->customer)->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$counter}"])->get(($this->tapUrl)($counter))->assertRedirect();
    }

    $this->actingAs($this->customer)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(($this->tapUrl)(3))->assertRedirect(route('taps.busy'));
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

it('tells a customer their tap expired when the scheduler expired it before they signed in', function (): void {
    $this->get(($this->tapUrl)(5));
    $this->travel(31)->minutes();
    app(ExpirePendingTaps::class)->handle();

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.result'));
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/refused')->where('reason', 'expired'));

    // Told once: coming back to /t/claim later goes home, not to that old result.
    $this->get(route('taps.claim'))->assertRedirect(route('home'));
});

it('moves a session\'s pending taps from before the claim token onto it, once', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->addSeconds(60)])->save());
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();
    // A session from before the deploy: its list of tap ids, no hash on the tap.
    $this->context->bypass(fn () => $tap->forceFill(['claim_token_hash' => null])->save());
    session()->put('taps.pending', [$tap->id]);

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.result'));

    expect(($this->stamps)())->toBe(3)
        ->and(session()->has('taps.pending'))->toBeFalse();
});

it('gives a session a new claim token at sign-in, so a session id planted before can\'t claim later taps', function (): void {
    $this->get(($this->tapUrl)(5));
    $planted = session('taps.token');

    $this->post(route('login.store'), ['email' => $this->customer->email, 'password' => 'password'])->assertRedirect(route('taps.claim'));

    // The tap from before sign-in moved onto the new token and is still claimed.
    expect(session('taps.token'))->toBeString()->not->toBe($planted);
    $this->get(route('taps.claim'))->assertRedirect(route('taps.result'));
    expect(($this->stamps)())->toBe(1);

    // A later signed-out tap in a browser still presenting the planted id lands under the old token: not this session's.
    $this->travel(1)->days();
    $this->get(route('home'));
    $later = $this->context->bypass(function () use ($planted): Tap {
        $tap = (new Tap)->forceFill([
            'status' => TapStatus::Pending, 'qty' => 1, 'expires_at' => now()->addMinutes(30),
            'nfc_tag_id' => $this->tag->id, 'counter' => 99, 'stamper_id' => $this->stamper->id,
            'business_id' => $this->stamper->business_id, 'location_id' => $this->stamper->location_id,
            'claim_token_hash' => hash('sha256', (string) $planted),
        ]);
        $tap->save();

        return $tap;
    });

    $this->get(route('taps.claim'))->assertRedirect(route('home'));
    expect(($this->statusOf)($later))->toBe(TapStatus::Pending)
        ->and(($this->stamps)())->toBe(1);
});

it('moves only an unclaimed, unowned pending or expired tap from an old session list', function (string $case): void {
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();
    $other = User::factory()->create();
    $this->context->bypass(fn () => $tap->forceFill(match ($case) {
        'owned' => ['claim_token_hash' => null, 'user_id' => $other->id],
        'already under a token' => ['claim_token_hash' => hash('sha256', 'another-token')],
        'stamped' => ['claim_token_hash' => null, 'status' => TapStatus::Rejected, 'rejection' => TapRejection::Replay],
    })->save());
    session()->put('taps.pending', [$tap->id]);

    $this->get(route('home'));

    expect(app(TapSession::class)->pendingTaps()->modelKeys())->toBe([]);
})->with(['owned', 'already under a token', 'stamped']);

it('moves a signed-in customer\'s own tap that failed to apply from an old session list', function (): void {
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();
    // Before the deploy: received while signed in, its stamp failed to apply, kept in the list.
    $this->context->bypass(fn () => $tap->forceFill(['claim_token_hash' => null, 'user_id' => $this->customer->id])->save());
    session()->put('taps.pending', [$tap->id]);

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.result'));

    expect(($this->stamps)())->toBe(1);
});

it('claims every tap waiting under the session\'s token, more than five included', function (): void {
    foreach (range(1, 7) as $counter) {
        $this->get(($this->tapUrl)($counter));
    }
    $hash = $this->context->bypass(fn (): ?string => Tap::query()->latest('id')->value('claim_token_hash'));
    // Seven under one token, as a move-over or a sign-in re-key can leave them.
    $this->context->bypass(fn (): int => Tap::query()->update(['claim_token_hash' => $hash]));

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('taps.result'));

    expect($this->context->bypass(fn (): int => Tap::query()->where('status', TapStatus::Pending)->count()))->toBe(0)
        ->and(app(TapSession::class)->hasPending())->toBeFalse();
});

it('no longer offers a tap that expired more than a day ago', function (): void {
    $this->get(($this->tapUrl)(5));
    $this->travel(31)->minutes();
    app(ExpirePendingTaps::class)->handle();
    $this->travel(2)->days();

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('home'));
});

it('no longer offers a pending tap more than a day past its expiry that the scheduler never expired', function (): void {
    $this->get(($this->tapUrl)(5));
    $this->travel(2)->days();

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('home'));
    expect(($this->stamps)())->toBe(0);
});

it('lets a sign-in through when moving its taps onto the new token fails', function (): void {
    Exceptions::fake();
    $this->get(($this->tapUrl)(5));
    $token = session('taps.token');
    DB::connection()->beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'update "taps" set "claim_token_hash"')) {
            throw new RuntimeException('The database went away.');
        }
    });

    $this->post(route('login.store'), ['email' => $this->customer->email, 'password' => 'password'])->assertRedirect(route('taps.claim'));

    Exceptions::assertReported(RuntimeException::class);
    expect(session('taps.token'))->toBe($token);
    $this->assertAuthenticatedAs($this->customer);
});

it('lets a claimed tap go from the session with its outcome', function (): void {
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();

    $this->actingAs($this->customer)->get(route('taps.claim'));

    expect($this->context->bypass(fn (): ?string => $tap->fresh()?->claim_token_hash))->toBeNull()
        ->and(($this->statusOf)($tap))->toBe(TapStatus::Stamped);
});

it('mints the same claim token for every request of a session that has none', function (): void {
    $this->get(route('home'));
    session()->forget('taps.token');
    $first = TapSession::ensureToken(session()->driver());
    session()->forget('taps.token');

    expect(TapSession::ensureToken(session()->driver()))->toBe($first)
        ->and($first)->not->toBe(session()->getId());
});

it('never claims a tap waiting under another session\'s claim token', function (): void {
    $this->get(($this->tapUrl)(5));
    $tap = ($this->lastTap)();
    session()->put('taps.token', 'another-sessions-token');

    $this->actingAs($this->customer)->get(route('taps.claim'))->assertRedirect(route('home'));

    expect(($this->stamps)())->toBe(0)
        ->and($this->context->bypass(fn (): ?int => $tap->fresh()?->user_id))->toBeNull();
});

it('saves every session with a claim token, also one ended during the request', function (): void {
    Route::middleware('web')->get('/_test/end-session', function (): string {
        session()->invalidate();

        return 'ended';
    });

    $this->get('/_test/end-session')->assertOk();

    expect(session('taps.token'))->toBeString();
});

it('works in Arabic, right to left', function (): void {
    $this->withCookie('locale', 'ar')->get(($this->tapUrl)(5));

    $this->withCookie('locale', 'ar')->get(route('taps.result'))
        ->assertInertia(fn (Assert $page): Assert => $page->component('tap/pending')->where('locale', 'ar')->where('dir', 'rtl'));
});
