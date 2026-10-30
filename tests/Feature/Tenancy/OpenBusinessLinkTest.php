<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\DocumentKind;
use App\Http\Middleware\SetTenant;
use App\Models\BusinessDocument;
use App\Models\BusinessMember;
use App\Models\User;
use App\Notifications\DailyCapStreakMail;
use App\Notifications\DocumentsDeletedUnreviewedMail;
use App\Notifications\NewDocumentsNeededMail;
use App\Notifications\StamperTappedWhileOffMail;
use App\Support\Auth\MailedLink;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Business emails open the business they name (CHW-295)
|--------------------------------------------------------------------------
|
| The verification emails and the fraud alerts go to one business's owners.
| Their button opens that business, whichever one the session had selected:
| a signed link selects it, when the user still works there, then shows its
| page. Anyone else gets nothing, and nothing is switched. Opened while
| another account is signed in, it says so (CHW-147, design 10e) and offers
| to sign out, then back to the link once the right account signs in.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->tenants->member($this->owner, $this->tenants->a2, BusinessRole::Owner);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->tenants->stamper($this->tenants->a2);

    // The link as mailed to $for (the owner unless said): A1's.
    $this->link = fn (string $page, ?User $for = null): string => MailedLink::signedForGood('business.open', ['business' => $this->tenants->a1->id, 'page' => $page, 'for' => ($for ?? $this->owner)->id]);
    // Signed in, with A2 the business selected.
    $this->onA2 = fn (?User $user = null) => $this->actingAs($user ?? $this->owner)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->a2->id]);
});

it('links each email to the business it names', function (): void {
    $a1 = $this->tenants->a1->id;
    $mails = [
        'verification' => [
            new NewDocumentsNeededMail($a1, 'A1', [DocumentKind::IdDocument], 'Blurry.', CarbonImmutable::parse('2027-01-23'), 'Africa/Casablanca'),
            new DocumentsDeletedUnreviewedMail($a1, 'A1', [DocumentKind::IdDocument]),
        ],
        'stampers' => [
            new DailyCapStreakMail($a1, 'Sara', 'Coffee card', 'A1', ['2026-10-15', '2026-10-16', '2026-10-17']),
            new StamperTappedWhileOffMail($a1, 'Till', $this->stamper->id, 'A1 site', CarbonImmutable::parse('2026-10-17 09:00'), 'Africa/Casablanca'),
        ],
    ];

    foreach ($mails as $page => $pageMails) {
        foreach ($pageMails as $mail) {
            expect($mail->toMail($this->owner)->actionUrl)->toBe(($this->link)($page))
                // Never mailed to anyone but a user: a link to the page itself would open whichever business is selected.
                ->and(fn (): MailMessage => $mail->toMail(new AnonymousNotifiable))->toThrow(LogicException::class);
        }
    }
});

it('selects the business named, then shows its verification page', function (): void {
    $this->context->bypass(fn () => BusinessDocument::factory()->for($this->tenants->a1)->create(['kind' => DocumentKind::IdDocument]));

    ($this->onA2)()->get(($this->link)('verification'))
        ->assertRedirect(route('verification.show'))
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a1->id);

    $this->get(route('verification.show'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('business/verification')
        ->where('documents.0.status', 'submitted'));
});

it('selects the business named, then shows its stampers', function (): void {
    ($this->onA2)()->get(($this->link)('stampers'))
        ->assertRedirect(route('stampers.index'))
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a1->id);

    $this->get(route('stampers.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('business/stampers')
        ->where('sites.0.id', $this->tenants->locationOf($this->tenants->a1)->id)
        ->where('sites.0.stampers.0.id', $this->stamper->id));
});

it('gives nothing, switching nothing, to whoever no longer works there', function (Closure $leave): void {
    $leave($this);

    ($this->onA2)()->get(($this->link)('verification'))
        ->assertNotFound()
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a2->id);
})->with([
    'membership removed' => [fn ($test) => $test->context->bypass(fn () => BusinessMember::query()
        ->where('user_id', $test->owner->id)->where('business_id', $test->tenants->a1->id)->delete())],
    'business suspended' => [fn ($test) => $test->context->bypass(fn () => $test->tenants->a1->forceFill(['status' => BusinessStatus::Suspended])->save())],
    'business archived' => [fn ($test) => $test->context->bypass(fn () => $test->tenants->a1->forceFill(['archived_at' => now()])->save())],
]);

it('gives nothing to anyone who never worked there', function (string $who): void {
    $user = match ($who) {
        'another business\'s owner' => $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner),
        'a franchisee\'s owner' => $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner),
        'a customer' => User::factory()->create(),
    };

    ($this->onA2)($user)->get(($this->link)('stampers', $user))
        ->assertNotFound()
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a2->id);
})->with(['another business\'s owner', 'a franchisee\'s owner', 'a customer']);

it('opens only for the user it was mailed to, switching nothing for anyone else', function (): void {
    $coOwner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->tenants->member($coOwner, $this->tenants->a2, BusinessRole::Owner);

    ($this->onA2)($coOwner)->get(($this->link)('stampers'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/other-account'))
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a2->id);
    ($this->onA2)($coOwner)->get(str_replace('for='.$this->owner->id, 'for='.$coOwner->id, ($this->link)('stampers')))->assertForbidden();
    ($this->onA2)($coOwner)->get(($this->link)('stampers', $coOwner))->assertRedirect(route('stampers.index'));
});

it('refuses a link it did not sign, switching nothing', function (): void {
    $signed = ($this->link)('stampers');

    ($this->onA2)()->get(route('business.open', ['business' => $this->tenants->a1->id, 'page' => 'stampers']))->assertForbidden();
    ($this->onA2)()->get(str_replace('/open/'.$this->tenants->a1->id.'/', '/open/'.$this->tenants->a2->id.'/', $signed))->assertForbidden()
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a2->id);
    ($this->onA2)()->get(MailedLink::onTheApp('/business/open/'.$this->tenants->a1->id.'/staff'))->assertNotFound();
});

it('opens it after signing in', function (): void {
    $link = ($this->link)('verification');

    $this->get($link)->assertRedirect(route('business.login'));
    $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect($link);

    $this->get($link)->assertRedirect(route('verification.show'))
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a1->id);
});

it('tells another account signed in whom the link is for, half hidden, and opens nothing', function (): void {
    $omar = User::factory()->create(['name' => 'Omar', 'email' => 'omar@example.com']);
    $this->owner->forceFill(['email' => 'salma@cafehafa.ma'])->save();
    $link = ($this->link)('verification');

    ($this->onA2)($omar)->get($link)
        ->assertForbidden()
        ->assertDontSee('salma@cafehafa.ma')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/other-account')
            ->where('sentTo', 's***@cafehafa.ma')
            ->where('signedInAs', 'omar@example.com')
            ->where('name', 'Omar')
            ->where('action', parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY)))
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a2->id);
});

it('gives a 404, and signs nobody out, for a link whose account is gone', function (string $how): void {
    $gone = User::factory()->create();
    $link = ($this->link)('verification', $gone);
    // Deleted, or anonymised: kept for the stamp ledger, but nobody can sign in to it.
    $how === 'deleted' ? $gone->delete() : $gone->forceFill(['anonymised_at' => now()])->save();

    ($this->onA2)()->get($link)->assertNotFound();
    ($this->onA2)()->post($link)->assertNotFound();

    $this->assertAuthenticatedAs($this->owner);
})->with(['deleted', 'anonymised']);

it('tells an account not verified yet too', function (): void {
    ($this->onA2)(User::factory()->unverified()->create())->get(($this->link)('verification'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/other-account'));
});

it('signs out the other account, then opens the link once the account it was mailed to signs in', function (): void {
    $omar = User::factory()->create();
    $link = ($this->link)('verification');

    $omar->forceFill(['remember_token' => 'kept-on-his-phone'])->save();

    ($this->onA2)($omar)->post($link)
        ->assertRedirect(route('business.login'))
        ->assertSessionMissing(SetTenant::SESSION_KEY);
    $this->assertGuest();
    // This browser only: his other devices stay signed in.
    expect($omar->fresh()->remember_token)->toBe('kept-on-his-phone');

    $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect($link);
    $this->get($link)->assertRedirect(route('verification.show'))
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a1->id);
});

it('signs nobody out for a link it did not sign', function (): void {
    $omar = User::factory()->create();

    ($this->onA2)($omar)->post(str_replace('for='.$this->owner->id, 'for='.$omar->id, ($this->link)('verification')))->assertForbidden();
    ($this->onA2)($omar)->post(route('business.open.switch-account', ['business' => $this->tenants->a1->id, 'page' => 'verification', 'for' => $this->owner->id]))->assertForbidden();

    $this->assertAuthenticatedAs($omar);
});

it('opens the link, signing nobody out, once the account it was mailed to is signed in', function (): void {
    $link = ($this->link)('verification');

    // The page left open in a tab, then the right account signed in in another.
    ($this->onA2)()->post($link)->assertRedirect($link);

    $this->assertAuthenticatedAs($this->owner);
});

it('reads whom the link is for from the signed link alone, never from a body', function (): void {
    $omar = User::factory()->create();
    $victim = User::factory()->create(['email' => 'zineb@elsewhere.ma']);
    $this->owner->forceFill(['email' => 'salma@cafehafa.ma'])->save();
    $link = ($this->link)('verification');
    $withBody = fn (User $for) => ($this->onA2)($omar)->call('GET', $link, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], content: (string) json_encode(['for' => $for->id]));

    // Naming another user: still the one it was mailed to, never anyone's address on demand.
    $withBody($victim)->assertForbidden()->assertInertia(fn (Assert $page): Assert => $page->where('sentTo', 's***@cafehafa.ma'));
    // Naming themselves: still not theirs.
    $withBody($omar)->assertForbidden()->assertInertia(fn (Assert $page): Assert => $page->component('auth/other-account'))
        ->assertSessionHas(SetTenant::SESSION_KEY, 'business:'.$this->tenants->a2->id);
});
