<?php

declare(strict_types=1);

use App\Actions\Staff\AcceptStaffInvitation;
use App\Actions\Staff\DescribeStaffInvitation;
use App\Actions\Staff\FindStaffInvitation;
use App\Actions\Staff\JoinStaff;
use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationMail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Inviting staff to a business (CHW-146)
|--------------------------------------------------------------------------
|
| An owner (or org admin) invites someone by email, optionally to one
| location: a signed link, good for StaffInvitation::DAYS. Whoever opens it
| from that inbox joins the business's staff: signed in as that address,
| after signing in, or with a new account. Staff sign in with a password or
| a passkey, never an email link (MaySignInByLink), so an account without a
| password chooses one as it joins. An invitation for one business never
| adds anyone anywhere else.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15 10:00:00');
    app()->setLocale('en');
    Notification::fake();
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);

    $this->as = fn (User $user, Business $business) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.$business->id]);
    $this->invite = fn (array $form, ?User $by = null, ?Business $in = null) => ($this->as)($by ?? $this->owner, $in ?? $this->tenants->a1)
        ->post(route('staff.invitations.store'), $form);
    // The last invitation link mailed to an address.
    $this->linkFor = function (string $email): string {
        $urls = new ArrayObject;
        Notification::assertSentOnDemand(StaffInvitationMail::class, function (StaffInvitationMail $mail, array $channels, AnonymousNotifiable $to) use ($email, $urls): bool {
            if ($to->routes['mail'] === $email) {
                $urls[] = $mail->url;
            }

            return $to->routes['mail'] === $email;
        });

        return (string) $urls[count($urls) - 1];
    };
    $this->memberships = fn (User $user) => $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $user->id)->get());
    $this->invitations = fn () => $this->context->bypass(fn () => StaffInvitation::query()->get());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the owner the staff page with the invite form and pending invitations', function (): void {
    ($this->invite)(['email' => 'karim@example.test']);

    ($this->as)($this->owner, $this->tenants->a1)->get(route('staff.index'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/staff')
            ->has('locations', 1)
            ->has('invitations', 1)
            ->where('invitations.0.email', 'karim@example.test'));
});

it('mails an invitation link from the owner, never from staff', function (): void {
    ($this->invite)(['email' => 'Karim@Example.test'])->assertSessionHasNoErrors()->assertRedirect(route('staff.index'));
    expect(($this->linkFor)('karim@example.test'))->toContain('/staff-invitations/');

    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    ($this->invite)(['email' => 'omar@example.test'], $staff)->assertForbidden();
    ($this->as)($staff, $this->tenants->a1)->get(route('staff.index'))->assertForbidden();

    expect(($this->invitations)()->pluck('email')->all())->toBe(['karim@example.test']);
});

it('replaces a pending invitation to the same address, whose old link stops working', function (): void {
    ($this->invite)(['email' => 'karim@example.test']);
    $first = ($this->linkFor)('karim@example.test');
    ($this->invite)(['email' => 'karim@example.test']);

    expect(($this->invitations)())->toHaveCount(1);
    $this->get($first)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
});

it('refuses an address already on the staff, and a location of another business', function (): void {
    $staff = $this->tenants->member(User::factory()->create(['email' => 'karim@example.test']), $this->tenants->a1);

    ($this->invite)(['email' => $staff->email])->assertSessionHasErrors(['email' => 'Already on your staff.']);
    ($this->invite)(['email' => 'nadia@example.test', 'location_id' => $this->tenants->locationOf($this->tenants->a2)->id])->assertSessionHasErrors(['location_id' => 'The selected location id is invalid.']);

    Notification::assertNothingSent();
});

it('limits how many invitations an owner mails in an hour', function (): void {
    foreach (range(1, 20) as $n) {
        ($this->invite)(['email' => "staff{$n}@example.test"])->assertSessionHasNoErrors();
    }

    ($this->invite)(['email' => 'staff21@example.test'])->assertTooManyRequests();
});

it('adds a signed-in account with the invited address to the staff, at the invited location', function (): void {
    // Not confirmed yet: the link, mailed to that inbox, confirms it.
    $karim = User::factory()->unverified()->create(['email' => 'karim@example.test']);
    $location = $this->tenants->locationOf($this->tenants->a1);
    ($this->invite)(['email' => 'karim@example.test', 'location_id' => $location->id]);
    $link = ($this->linkFor)('karim@example.test');

    $this->actingAs($karim)->get($link)->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/staff-invitation')
            ->where('state', 'join')->where('businessName', 'A1')->where('locationName', $location->name));
    expect(($this->memberships)($karim))->toHaveCount(0);

    $this->actingAs($karim)->post($link)->assertRedirect(route('dashboard'));

    $membership = ($this->memberships)($karim)->sole();
    expect($membership)
        ->business_id->toBe($this->tenants->a1->id)
        ->organization_id->toBe($this->tenants->orgA->id)
        ->role->toBe(BusinessRole::Staff)
        ->location_id->toBe($location->id)
        ->and(session(SetTenant::SESSION_KEY))->toBe('business:'.$this->tenants->a1->id)
        ->and($karim->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(($this->invitations)()->sole()->accepted_at)->not->toBeNull();

    // Once.
    $this->actingAs($karim)->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
});

it('asks an account without a password to choose one as it joins (staff never sign in by link)', function (): void {
    $salma = User::factory()->create(['email' => 'salma@example.test', 'password' => null]);
    ($this->invite)(['email' => 'salma@example.test']);
    $link = ($this->linkFor)('salma@example.test');

    $this->actingAs($salma)->get($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'choose-password'));
    $this->actingAs($salma)->post($link)->assertSessionHasErrors('password');
    expect(($this->memberships)($salma))->toHaveCount(0);

    $this->actingAs($salma)->post($link, ['password' => 'a-long-test-password-1', 'password_confirmation' => 'a-long-test-password-1'])
        ->assertRedirect(route('dashboard'));

    expect(Hash::check('a-long-test-password-1', (string) $salma->fresh()->password))->toBeTrue()
        ->and(($this->memberships)($salma))->toHaveCount(1);
});

it('signs up someone new from the link: verified, consent dated, then on the staff', function (): void {
    ($this->invite)(['email' => 'nadia@example.test']);
    $link = ($this->linkFor)('nadia@example.test');
    auth()->logout();

    $this->get($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'sign-up')->where('email', 'nadia@example.test'));
    $this->post($link, ['name' => 'Nadia', 'password' => 'a-long-test-password-1', 'password_confirmation' => 'a-long-test-password-1'])
        ->assertSessionHasErrors('adult');
    $this->assertGuest();

    // An address of their own in the form changes nothing: the account is the invited one.
    $this->post($link, ['name' => 'Nadia', 'email' => 'attacker@example.test', 'password' => 'a-long-test-password-1', 'password_confirmation' => 'a-long-test-password-1', 'adult' => '1'])
        ->assertRedirect(route('dashboard'));
    expect(User::query()->where('email', 'attacker@example.test')->exists())->toBeFalse();

    $nadia = User::query()->where('email', 'nadia@example.test')->sole();
    $this->assertAuthenticatedAs($nadia);
    expect($nadia)
        ->hasVerifiedEmail()->toBeTrue()
        ->adult_confirmed_at->not->toBeNull()
        ->marketing_opt_in_at->toBeNull()
        ->and(($this->memberships)($nadia))->toHaveCount(1);
});

it('asks a guest whose address has an account to sign in first, then brings them back', function (): void {
    User::factory()->create(['email' => 'karim@example.test']);
    ($this->invite)(['email' => 'karim@example.test']);
    $link = ($this->linkFor)('karim@example.test');
    auth()->logout();

    $this->get($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'sign-in'));
    expect(session('url.intended'))->toBe($link);

    // Nothing joins without signing in.
    $this->post($link)->assertRedirect(route('login'));
    $this->assertGuest();
});

it('never lets another account use the invitation', function (): void {
    ($this->invite)(['email' => 'karim@example.test']);
    $link = ($this->linkFor)('karim@example.test');
    $someoneElse = User::factory()->create();

    $this->actingAs($someoneElse)->get($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'other-account')->where('email', 'k***@example.test'));
    $this->actingAs($someoneElse)->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'other-account'));

    expect(($this->memberships)($someoneElse))->toHaveCount(0)
        ->and(($this->invitations)()->sole()->accepted_at)->toBeNull();
});

it('refuses an invitation past its time, or with a changed link', function (string $how): void {
    $karim = User::factory()->create(['email' => 'karim@example.test']);
    ($this->invite)(['email' => 'karim@example.test']);
    $link = ($this->linkFor)('karim@example.test');

    if ($how === 'out of date') {
        $this->travel(StaffInvitation::DAYS)->days();
        $this->travel(1)->seconds();
    } else {
        $link = str_replace('/staff-invitations/', '/staff-invitations/9', $link);
    }

    $this->actingAs($karim)->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
    expect(($this->memberships)($karim))->toHaveCount(0);
})->with(['out of date', 'changed link']);

it('never demotes an owner who opens an invitation to their own business', function (): void {
    ($this->invite)(['email' => 'nadia@example.test']);
    // Made an owner meanwhile.
    $nadia = $this->tenants->member(User::factory()->create(['email' => 'nadia@example.test']), $this->tenants->a1, BusinessRole::Owner);

    $this->actingAs($nadia)->post(($this->linkFor)('nadia@example.test'))->assertRedirect(route('dashboard'));

    expect(($this->memberships)($nadia)->sole()->role)->toBe(BusinessRole::Owner);
});

it('adds the invited business only, never another franchisee or organization (both boundaries)', function (): void {
    $karim = User::factory()->create(['email' => 'karim@example.test']);
    ($this->invite)(['email' => 'karim@example.test']);

    $this->actingAs($karim)->post(($this->linkFor)('karim@example.test'));

    expect(($this->memberships)($karim)->pluck('business_id')->all())->toBe([$this->tenants->a1->id]);
});

it('keeps each business\'s invitations to itself (both boundaries)', function (string $other): void {
    ($this->invite)(['email' => 'karim@example.test']);
    $business = $this->tenants->{$other};
    $theirOwner = $this->tenants->member(User::factory()->create(), $business, BusinessRole::Owner);

    ($this->as)($theirOwner, $business)->get(route('staff.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->has('invitations', 0));
    $this->context->set($business->organization, $business, businessRole: BusinessRole::Owner);
    expect(StaffInvitation::query()->count())->toBe(0);

    // Naming A1 in the form changes nothing: they only ever invite into their own.
    ($this->invite)(['email' => 'nadia@example.test', 'business_id' => $this->tenants->a1->id], $theirOwner, $business);
    expect(($this->invitations)()->firstWhere('email', 'nadia@example.test')->business_id)->toBe($business->id);
})->with(['franchisee A2' => ['a2'], 'organization B' => ['b1']]);

it('refuses an invitation to a business or location closed since', function (): void {
    $karim = User::factory()->create(['email' => 'karim@example.test']);
    ($this->invite)(['email' => 'karim@example.test']);
    $this->context->bypass(fn () => Business::query()->whereKey($this->tenants->a1->id)->update(['archived_at' => now()]));
    $link = ($this->linkFor)('karim@example.test');

    $this->actingAs($karim)->get($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
    $this->actingAs($karim)->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
    // The Action too, should a closure land between the page and the button.
    expect(app(AcceptStaffInvitation::class)->handle(($this->invitations)()->sole()->id, $karim))->toBeFalse()
        ->and(($this->memberships)($karim))->toHaveCount(0);
});

it('joins only the invited address, only while in date, whoever calls the Action', function (): void {
    $karim = User::factory()->create(['email' => 'karim@example.test']);
    ($this->invite)(['email' => 'karim@example.test']);
    $id = ($this->invitations)()->sole()->id;
    $accept = app(AcceptStaffInvitation::class);

    expect($accept->handle($id, User::factory()->create()))->toBeFalse();

    $this->travel(StaffInvitation::DAYS)->days();
    $this->travel(1)->seconds();

    expect($accept->handle($id, $karim))->toBeFalse()
        ->and(app(FindStaffInvitation::class)->handle($id))->toBeNull()
        ->and(($this->memberships)($karim))->toHaveCount(0);
});

it('keeps an org admin working across the organization off the staff page (no business chosen)', function (): void {
    $admin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    $this->actingAs($admin)->get(route('staff.index'))->assertForbidden();
    $this->actingAs($admin)->post(route('staff.invitations.store'), ['email' => 'karim@example.test'])->assertForbidden();

    Notification::assertNothingSent();
});

it('keeps none of it when the invitation stops working between the page and the button', function (): void {
    ($this->invite)(['email' => 'nadia@example.test']);
    $invitation = app(FindStaffInvitation::class)->handle(($this->invitations)()->sole()->id);
    $this->context->bypass(fn () => Business::query()->whereKey($this->tenants->a1->id)->update(['archived_at' => now()]));

    $joined = app(JoinStaff::class)->handle($invitation, DescribeStaffInvitation::SIGN_UP, null, [
        'name' => 'Nadia', 'password' => 'a-long-test-password-1', 'password_confirmation' => 'a-long-test-password-1', 'adult' => '1', 'marketing' => '',
    ], 'session-id');

    expect($joined)->toBeNull()
        ->and(User::query()->where('email', 'nadia@example.test')->exists())->toBeFalse();
});

it('mails the business name as written, never as a link the owner put in it', function (): void {
    $this->context->bypass(fn () => Business::query()->whereKey($this->tenants->a1->id)->update(['name' => '[Confirm your bank](https://evil.example) https://evil.example']));

    ($this->invite)(['email' => 'karim@example.test']);

    Notification::assertSentOnDemand(StaffInvitationMail::class, function (StaffInvitationMail $mail): bool {
        $html = (string) $mail->toMail(new AnonymousNotifiable)->render();

        expect($html)->not->toContain('href="https://evil.example')
            ->and($html)->toContain('[Confirm your bank](https://evil.example)');

        return true;
    });
});
