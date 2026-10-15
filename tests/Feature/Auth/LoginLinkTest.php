<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\PlatformRole;
use App\Enums\TapStatus;
use App\Jobs\MailLoginLink;
use App\Models\LoginLink;
use App\Models\NfcTag;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Notifications\LoginLinkMail;
use App\Notifications\SignInWithPasswordMail;
use App\Support\Auth\ProvenEmail;
use App\Support\Http\ClientAddress;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Support\Mailbox;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Signing in with an email link (CHW-23, C1)
|--------------------------------------------------------------------------
|
| A customer types their email and taps the link we send: signed, good for
| 15 minutes, once. An existing customer is signed in for a year; a new
| address first says who they are (name, 16 or older, news by email or
| not), then has an account with no password. Only customers: an account
| with two-factor, a business or staff role, or the platform admin, is told
| to use its password, so a link never skips a second factor. The answer
| is the same for every address: it never says who has an account.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-14 10:00:00');
    app()->setLocale('en');
    $this->context = app(TenantContext::class);

    $this->requestLink = fn (string $email) => $this->post(route('login-link.store'), ['email' => $email]);
    // The last link mailed to an address (its account's, or the address itself), as the customer would tap it.
    $this->linkFor = function (string $email): string {
        $urls = new ArrayObject;
        $capture = function (LoginLinkMail $mail) use ($urls): bool {
            $urls[] = $mail->url;

            return true;
        };
        $account = User::query()->where('email', $email)->first();

        $account instanceof User
            ? Notification::assertSentTo($account, LoginLinkMail::class, $capture)
            : Notification::assertSentOnDemand(LoginLinkMail::class, fn (LoginLinkMail $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === $email && $capture($mail));

        return (string) $urls[count($urls) - 1];
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('asks guests for their email', function (): void {
    $this->get(route('login-link.create'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/email-link')->where('minutes', LoginLink::MINUTES));

    $this->actingAs(User::factory()->create())->get(route('login-link.create'))->assertRedirect();
});

it('mails a link to a customer and to a new address, with the same answer for both', function (): void {
    Notification::fake();
    $customer = User::factory()->create(['email' => 'salma@example.test']);

    ($this->requestLink)('Salma@Example.test')->assertRedirect()->assertSessionHas('status', 'login-link-sent');
    ($this->requestLink)('nadia@example.test')->assertRedirect()->assertSessionHas('status', 'login-link-sent');

    Notification::assertSentTo($customer, LoginLinkMail::class);
    Notification::assertSentOnDemand(LoginLinkMail::class, fn (LoginLinkMail $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'nadia@example.test');
    expect(LoginLink::query()->pluck('email')->all())->toBe(['salma@example.test', 'nadia@example.test']);
});

it('never mails a link to an account it would let skip a second factor', function (string $account): void {
    Notification::fake();
    $user = User::factory()->create();
    $tenants = Tenants::make();

    match ($account) {
        'two-factor on' => $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save(),
        'a business owner' => $tenants->member($user, $tenants->a1, BusinessRole::Owner),
        'staff' => $tenants->member($user, $tenants->b1),
        'an org admin' => $tenants->admin($user, $tenants->orgA),
        'the platform admin' => $user->assignRole(Role::findOrCreate(PlatformRole::Admin->value)),
        // Registered with a password, never confirmed: perhaps by someone else, perhaps a changed address.
        'unconfirmed, with a password' => $user->forceFill(['email_verified_at' => null])->save(),
        // Without a password, an address changed in settings and not confirmed: perhaps someone else's.
        'unconfirmed, without a password' => $user->forceFill(['email_verified_at' => null, 'password' => null])->save(),
    };

    ($this->requestLink)($user->email)->assertRedirect()->assertSessionHas('status', 'login-link-sent');

    Notification::assertNotSentTo($user, LoginLinkMail::class);
    // An account that never chose a password (signed up by link, then two-factor on) is told how to choose one.
    Notification::assertSentTo($user, SignInWithPasswordMail::class, fn (SignInWithPasswordMail $mail): bool => str_contains(implode(' ', $mail->toMail($user)->outroLines), route('password.request')));
    expect(LoginLink::query()->count())->toBe(0);
})->with(['two-factor on', 'a business owner', 'staff', 'an org admin', 'the platform admin', 'unconfirmed, with a password', 'unconfirmed, without a password']);

it('limits the links per address typed from one client address, so nobody else can use up a customer\'s', function (): void {
    Notification::fake();
    $request = fn (string $from) => $this->withServerVariables(['REMOTE_ADDR' => $from])->post(route('login-link.store'), ['email' => 'salma@example.test']);

    foreach (range(1, 3) as $attempt) {
        $request('203.0.113.7')->assertSessionHas('status', 'login-link-sent');
    }

    $request('203.0.113.7')->assertSessionHasErrors('email');
    // The customer, from their own phone, still gets theirs.
    $request('198.51.100.9')->assertSessionHas('status', 'login-link-sent');
    expect(LoginLink::query()->count())->toBe(4);
});

it('limits links per browser, and far more loosely per client address (customers share a carrier\'s)', function (): void {
    $request = Request::create(route('login-link.store'), 'POST', ['email' => ' Salma@Example.test '], server: ['REMOTE_ADDR' => '203.0.113.7']);
    $request->setLaravelSession($session = app('session')->driver());
    $session->start();
    $address = ClientAddress::rateLimitKey('203.0.113.7');

    $limits = RateLimiter::limiter('login-link')($request);

    expect(array_map(fn (Limit $limit): array => [$limit->maxAttempts, $limit->decaySeconds, $limit->key], $limits))->toBe([
        [3, 900, 'login-link:email:'.sha1('salma@example.test').':'.$address],
        // From anywhere: an inbox gets so many links an hour at most.
        [10, 3600, 'login-link:email:'.sha1('salma@example.test')],
        [10, 3600, 'login-link:session:'.$session->getId()],
        [100, 3600, 'login-link:address:'.$address],
    ]);
});

it('signs a customer in for a year from the link, once they confirm (a mail scanner opening it spends nothing)', function (): void {
    Notification::fake();
    // A customer without a password, signed up by link: their passkey stays.
    $customer = User::factory()->create(['email' => 'salma@example.test', 'password' => null]);
    DB::table('passkeys')->insert(['user_id' => $customer->id, 'name' => 'Their phone', 'credential_id' => 'theirs', 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    ($this->requestLink)('salma@example.test');
    $link = ($this->linkFor)('salma@example.test');

    // Opening the link only shows its button.
    $this->get($link)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('auth/email-link-open')->where('expired', false));
    $this->get($link)->assertOk();
    $this->assertGuest();

    $response = $this->post($link)->assertRedirect(route('start'));

    $this->assertAuthenticatedAs($customer);
    expect($customer->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(DB::table('passkeys')->where('user_id', $customer->id)->exists())->toBeTrue()
        ->and(session('auth.password_confirmed_at'))->toBe(now()->unix());

    $remember = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === Auth::guard()->getRecallerName());
    expect($remember)->not->toBeNull()
        ->and(Carbon::createFromTimestamp($remember->getExpiresTime())->diffInDays(now(), absolute: true))->toBeBetween(364.0, 366.0);
});

it('refuses a link tampered with, out of date or used already', function (Closure $spoil): void {
    Notification::fake();
    User::factory()->create(['email' => 'salma@example.test']);
    ($this->requestLink)('salma@example.test');
    $link = $spoil(($this->linkFor)('salma@example.test'), $this);

    $this->get($link)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('auth/email-link-open')->where('expired', true));
    $this->post($link)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('auth/email-link-open')->where('expired', true));
    $this->assertGuest();
})->with([
    'tampered' => fn (string $link): string => (string) preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $link),
    'out of date' => function (string $link, $test): string {
        $test->travel(16)->minutes();

        return $link;
    },
    'used already' => function (string $link, $test): string {
        $test->post($link);
        Auth::logout();
        $test->flushSession();

        return $link;
    },
]);

it('refuses the link of an account that turned on two-factor since', function (): void {
    Notification::fake();
    $customer = User::factory()->create(['email' => 'salma@example.test']);
    ($this->requestLink)('salma@example.test');
    $link = ($this->linkFor)('salma@example.test');
    $customer->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    $this->post($link)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('auth/email-link-open')->where('expired', true));
    $this->assertGuest();
});

it('asks a new address who they are, then makes an account without a password', function (): void {
    Notification::fake();
    ($this->requestLink)('nadia@example.test');
    $this->post(($this->linkFor)('nadia@example.test'))->assertRedirect(route('sign-up.finish'));
    $this->assertGuest();

    $this->get(route('sign-up.finish'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/finish-sign-up')->where('email', 'nadia@example.test'));

    // 16 or older, or no account.
    $this->post(route('sign-up.finish.store'), ['name' => 'Nadia Tazi'])->assertSessionHasErrors('adult');
    $this->assertGuest();

    $this->post(route('sign-up.finish.store'), ['name' => 'Nadia Tazi', 'adult' => '1'])->assertRedirect(route('start'));

    $nadia = User::query()->where('email', 'nadia@example.test')->sole();
    $this->assertAuthenticatedAs($nadia);
    expect($nadia->name)->toBe('Nadia Tazi')
        ->and($nadia->password)->toBeNull()
        ->and($nadia->hasVerifiedEmail())->toBeTrue()
        ->and($nadia->adult_confirmed_at?->equalTo(now()))->toBeTrue()
        // News by email only when ticked: never assumed.
        ->and($nadia->marketing_opt_in_at)->toBeNull();

    // No password ever signs in an account without one.
    Auth::logout();
    $this->post(route('login.store'), ['email' => 'nadia@example.test', 'password' => ''])->assertSessionHasErrors();
    $this->assertGuest();
});

it('records news by email only when ticked, with when', function (): void {
    Notification::fake();
    ($this->requestLink)('nadia@example.test');
    $this->post(($this->linkFor)('nadia@example.test'));

    $this->post(route('sign-up.finish.store'), ['name' => 'Nadia Tazi', 'adult' => '1', 'marketing' => '1']);

    expect(User::query()->where('email', 'nadia@example.test')->sole()->marketing_opt_in_at?->equalTo(now()))->toBeTrue();
});

it('lets nobody finish a sign-up without a link opened in the last 15 minutes', function (): void {
    $this->get(route('sign-up.finish'))->assertRedirect(route('login-link.create'));
    $this->post(route('sign-up.finish.store'), ['name' => 'Nadia Tazi', 'adult' => '1'])->assertRedirect(route('login-link.create'));

    Notification::fake();
    ($this->requestLink)('nadia@example.test');
    $this->post(($this->linkFor)('nadia@example.test'));
    $this->travel(16)->minutes();

    $this->get(route('sign-up.finish'))->assertRedirect(route('login-link.create'));
    expect(User::query()->count())->toBe(0);
});

it('applies a signed-out tap once the new customer has signed up by link', function (): void {
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);
    $tenants = Tenants::make();
    $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($tenants->stamper($tenants->a1)->nfc_tag_id));
    Notification::fake();

    $this->get(route('taps.receive', app(FakeTap::class)->build($tag->uid, 5)))->assertRedirect(route('taps.result'));
    ($this->requestLink)('nadia@example.test');
    $this->post(($this->linkFor)('nadia@example.test'));
    $this->post(route('sign-up.finish.store'), ['name' => 'Nadia Tazi', 'adult' => '1'])->assertRedirect(route('taps.claim'));
    $this->get(route('taps.claim'))->assertRedirect(route('taps.result'));
    $this->get(route('taps.claim'));

    $this->context->bypass(function (): void {
        expect(Tap::query()->sole()->status)->toBe(TapStatus::Stamped)
            ->and((int) StampEvent::query()->sum('qty'))->toBe(1);
    });
});

it('sends an existing customer back to their waiting tap', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'salma@example.test']);
    ($this->requestLink)('salma@example.test');

    $this->withSession(['url.intended' => route('taps.claim')])
        ->post(($this->linkFor)('salma@example.test'))
        ->assertRedirect(route('taps.claim'));
});

it('keeps a customer signed in through the year, after their session ended', function (): void {
    Notification::fake();
    $customer = User::factory()->create(['email' => 'salma@example.test']);
    ($this->requestLink)('salma@example.test');
    $response = $this->post(($this->linkFor)('salma@example.test'));
    $remember = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === Auth::guard()->getRecallerName());

    // Months later, the session long gone: only the remember cookie is left.
    $this->travel(200)->days();
    $this->flushSession();
    Auth::forgetGuards();

    // As the browser sends it back: still encrypted.
    $this->withUnencryptedCookie($remember->getName(), (string) $remember->getValue())
        ->get(route('app.cards'))->assertOk();
    $this->assertAuthenticatedAs($customer);
});

it('mails the link in the customer\'s language, right to left in Arabic', function (): void {
    User::factory()->create(['email' => 'salma@example.test', 'locale' => 'ar']);

    ($this->requestLink)('salma@example.test');

    $mail = Mailbox::lastEmail();
    expect($mail->getSubject())->toBe(__('Your punchcard sign-in link', locale: 'ar'))
        ->and((string) $mail->getHtmlBody())->toContain('dir="rtl"');
});

it('prints a working link for the end-to-end tests, which can\'t read mail', function (): void {
    Artisan::call('punchcard:login-link', ['email' => 'Nadia@Example.test']);
    $link = trim(Artisan::output());

    // On any name of the app: the signature leaves the host out.
    $this->post(str_replace(config('app.url'), 'http://127.0.0.1:8000', $link))->assertRedirect(route('sign-up.finish'));
    expect(LoginLink::query()->sole()->email)->toBe('nadia@example.test');
});

it('prints sign-in links only in local and testing', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    try {
        expect(Artisan::call('punchcard:login-link', ['email' => 'nadia@example.test']))->toBe(1)
            ->and(LoginLink::query()->count())->toBe(0);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
})->with(['production', 'staging']);

it('refuses a link past its time, whatever its signature says', function (): void {
    $link = LoginLink::for('salma@example.test');
    User::factory()->create(['email' => 'salma@example.test']);
    $this->travel(16)->minutes();

    // Signed again for later: only the row says it is out of date.
    $resigned = url(URL::temporarySignedRoute('login-link.show', now()->addHour(), ['link' => $link->id], absolute: false));

    $this->get($resigned)->assertInertia(fn (Assert $page): Assert => $page->where('expired', true));
    $this->post($resigned)->assertInertia(fn (Assert $page): Assert => $page->where('expired', true));
    $this->assertGuest();
});

it('never confirms an account with a password through a link (it may be someone else\'s, or a mistyped new address)', function (): void {
    Notification::fake();
    ($this->requestLink)('salma@example.test');
    $link = ($this->linkFor)('salma@example.test');
    // Registered with a password before the link was used, never confirmed.
    $account = User::factory()->unverified()->create(['email' => 'salma@example.test']);
    $password = $account->password;

    $this->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('expired', true));

    $this->assertGuest();
    expect($account->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($account->fresh()->password)->toBe($password);
});

it('keeps the password of a confirmed account that signs in by link', function (): void {
    Notification::fake();
    $customer = User::factory()->create(['email' => 'salma@example.test']);
    $password = $customer->password;

    ($this->requestLink)('salma@example.test');
    $this->post(($this->linkFor)('salma@example.test'));

    expect($customer->fresh()->password)->toBe($password);
});

it('builds the link on the app\'s own address, whatever host the request names', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'salma@example.test']);

    $this->withServerVariables(['HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'evil.example'])
        ->post('http://evil.example/login/email', ['email' => 'salma@example.test']);

    expect(($this->linkFor)('salma@example.test'))->toStartWith(rtrim((string) config('app.url'), '/').'/login/link/');
});

it('gives the browser a new session once a link proved a new address', function (): void {
    Notification::fake();
    ($this->requestLink)('nadia@example.test');
    $link = ($this->linkFor)('nadia@example.test');
    // A session id someone planted in this browser beforehand.
    $planted = session()->getId();

    $this->withCookie((string) config('session.cookie'), $planted)->post($link)->assertRedirect(route('sign-up.finish'));

    expect(session()->getId())->not->toBe($planted)
        ->and(ProvenEmail::of(session()->driver()))->toBe('nadia@example.test');
});

it('says so when the address can no longer sign up by link, before the finish screen is sent', function (string $account): void {
    Notification::fake();
    ($this->requestLink)('nadia@example.test');
    $this->post(($this->linkFor)('nadia@example.test'));
    $appeared = User::factory()->unverified()->create(['email' => 'nadia@example.test']);

    if ($account === 'with two-factor') {
        $appeared->forceFill(['email_verified_at' => now(), 'two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    }

    $this->post(route('sign-up.finish.store'), ['name' => 'Nadia Tazi', 'adult' => '1'])
        ->assertRedirect(route('login-link.create'))
        ->assertSessionHasErrors(['email' => __('This address signs in with its password or a passkey.')]);

    $this->assertGuest();
    expect(ProvenEmail::of(session()->driver()))->toBeNull()
        ->and(User::query()->where('email', 'nadia@example.test')->count())->toBe(1);
})->with(['unconfirmed, with a password', 'with two-factor']);

it('records the consent given on the finish screen, also on an account confirmed meanwhile', function (): void {
    Notification::fake();
    ($this->requestLink)('nadia@example.test');
    $this->post(($this->linkFor)('nadia@example.test'));
    $customer = User::factory()->create(['email' => 'nadia@example.test', 'name' => 'Nadia T.']);

    $this->post(route('sign-up.finish.store'), ['name' => 'Nadia Tazi', 'adult' => '1', 'marketing' => '1'])->assertRedirect(route('start'));

    $customer->refresh();
    $this->assertAuthenticatedAs($customer);
    expect($customer->adult_confirmed_at?->equalTo(now()))->toBeTrue()
        ->and($customer->marketing_opt_in_at?->equalTo(now()))->toBeTrue()
        // Their name is theirs already.
        ->and($customer->name)->toBe('Nadia T.');
});

it('stores an address in lower case, however it is typed, so a link finds its account', function (): void {
    Notification::fake();
    $customer = User::factory()->create(['email' => 'salma@example.test', 'password' => null]);

    $this->actingAs($customer)->withSession(['auth.password_confirmed_at' => now()->unix()])->patch(route('profile.update'), ['name' => $customer->name, 'email' => 'Salma.New@Example.TEST']);
    Auth::logout();
    // Confirmed from the mail settings sent, as a changed address must be before a link signs in to it.
    $customer->fresh()->markEmailAsVerified();
    ($this->requestLink)('salma.new@example.test');

    expect($customer->fresh()->email)->toBe('salma.new@example.test');
    Notification::assertSentTo($customer, LoginLinkMail::class);
    expect(User::query()->count())->toBe(1);
});

it('never lets an address someone put on their account sign its owner in to that account', function (): void {
    Notification::fake();
    // Someone signed up by link, then put another person's address on their account (unconfirmed).
    $theirs = User::factory()->create(['email' => 'someone@example.test', 'password' => null]);
    $this->actingAs($theirs)->withSession(['auth.password_confirmed_at' => now()->unix()])->patch(route('profile.update'), ['name' => $theirs->name, 'email' => 'salma@example.test']);
    Auth::logout();

    // The address's owner asks for a link: none, so nobody signs in to that account by it.
    ($this->requestLink)('salma@example.test')->assertSessionHas('status', 'login-link-sent');

    Notification::assertNotSentTo($theirs->fresh(), LoginLinkMail::class);
    expect(LoginLink::query()->count())->toBe(0)
        ->and($theirs->fresh()->hasVerifiedEmail())->toBeFalse();
    $this->assertGuest();
});

it('refuses a case variant of a taken address in settings, without an error page', function (): void {
    User::factory()->create(['email' => 'salma@example.test']);
    $other = User::factory()->create();

    $this->actingAs($other)->withSession(['auth.password_confirmed_at' => now()->unix()])->patch(route('profile.update'), ['name' => $other->name, 'email' => 'Salma@Example.test'])
        ->assertSessionHasErrors('email');
});

it('does the work of a link in a queued job, so the answer takes as long for any address', function (): void {
    Queue::fake();
    User::factory()->create(['email' => 'salma@example.test']);

    ($this->requestLink)('salma@example.test')->assertSessionHas('status', 'login-link-sent');

    Queue::assertPushed(MailLoginLink::class, fn (MailLoginLink $job): bool => $job->email === 'salma@example.test' && $job->locale === 'en');
    expect(LoginLink::query()->count())->toBe(0);
});

it('answers a malformed address like any wrong one', function (): void {
    $this->post(route('login-link.store'), ['email' => ['salma@example.test']])->assertSessionHasErrors('email');
});

it('says on the link\'s page which account it signs in to, half hidden', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'salma@example.test']);
    ($this->requestLink)('salma@example.test');

    $link = ($this->linkFor)('salma@example.test');

    $this->get($link)
        ->assertInertia(fn (Assert $page): Assert => $page->where('email', 's***@example.test')
            // Its own path: never the host or scheme a proxy may have changed.
            ->where('action', parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY)));
});

it('forgets the links a day after they were sent (the address is personal data)', function (): void {
    $old = LoginLink::for('salma@example.test');
    $this->travel(25)->hours();
    $recent = LoginLink::for('nadia@example.test');

    Artisan::call('model:prune', ['--model' => [LoginLink::class]]);

    expect(LoginLink::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(LoginLink::query()->find($old->id))->toBeNull();
});
