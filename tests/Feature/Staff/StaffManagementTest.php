<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationMail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Seeing and removing a business's staff (CHW-146)
|--------------------------------------------------------------------------
|
| The owner's staff page lists who works there (name, address, role, the
| one location staff may be limited to) and the invitations still waiting.
| Removing someone ends their membership, never their account; owners are
| not removed here (handing a business over is CHW-140). Cancelling an
| invitation makes its link stop working. Only this business's, never
| another franchisee's or organization's.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    Notification::fake();
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(['name' => 'Nour Owner']), $this->tenants->a1, BusinessRole::Owner);
    $this->staff = $this->tenants->member(User::factory()->create(['name' => 'Karim Staff']), $this->tenants->a1);

    $this->as = fn (User $user, Business $business) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.$business->id]);
    $this->membershipOf = fn (User $user, Business $business): ?BusinessMember => $this->context->bypass(
        fn (): ?BusinessMember => BusinessMember::query()->where('business_id', $business->id)->where('user_id', $user->id)->first(),
    );
});

it('lists the business\'s staff with their role and location, owners first', function (): void {
    $location = $this->tenants->locationOf($this->tenants->a1);
    $this->context->bypass(fn () => ($this->membershipOf)($this->staff, $this->tenants->a1)->forceFill(['location_id' => $location->id])->save());

    ($this->as)($this->owner, $this->tenants->a1)->get(route('staff.index'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/staff')
            ->has('members', 2)
            ->where('members.0.name', 'Nour Owner')
            ->where('members.0.role', 'owner')
            ->where('members.0.removable', false)
            ->where('members.1.name', 'Karim Staff')
            ->where('members.1.role', 'staff')
            ->where('members.1.location', $location->name)
            ->where('members.1.removable', true));
});

it('removes a staff member from the business, never their account', function (): void {
    $membership = ($this->membershipOf)($this->staff, $this->tenants->a1);

    ($this->as)($this->owner, $this->tenants->a1)->delete(route('staff.destroy', $membership->id))->assertRedirect(route('staff.index'));

    expect(($this->membershipOf)($this->staff, $this->tenants->a1))->toBeNull()
        ->and($this->staff->fresh())->not->toBeNull();
});

it('lets neither staff remove anyone, nor an owner be removed here', function (): void {
    $other = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    ($this->as)($this->staff, $this->tenants->a1)->delete(route('staff.destroy', ($this->membershipOf)($other, $this->tenants->a1)->id))->assertForbidden();
    // An owner, even one of two (so not the last): handed over (CHW-140), never removed here.
    $coOwner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    ($this->as)($this->owner, $this->tenants->a1)->delete(route('staff.destroy', ($this->membershipOf)($coOwner, $this->tenants->a1)->id))->assertForbidden();

    expect(($this->membershipOf)($other, $this->tenants->a1))->not->toBeNull()
        ->and(($this->membershipOf)($coOwner, $this->tenants->a1))->not->toBeNull();
});

it('never shows or removes another business\'s staff (both boundaries)', function (string $other): void {
    $business = $this->tenants->{$other};
    $theirStaff = $this->tenants->member(User::factory()->create(['name' => 'Theirs']), $business);

    ($this->as)($this->owner, $this->tenants->a1)->get(route('staff.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->has('members', 2)->where('members.1.name', 'Karim Staff'));
    ($this->as)($this->owner, $this->tenants->a1)->delete(route('staff.destroy', ($this->membershipOf)($theirStaff, $business)->id))->assertNotFound();

    expect(($this->membershipOf)($theirStaff, $business))->not->toBeNull();
})->with(['franchisee A2' => ['a2'], 'organization B' => ['b1']]);

it('cancels an invitation: its link stops working', function (): void {
    ($this->as)($this->owner, $this->tenants->a1)->post(route('staff.invitations.store'), ['email' => 'nadia@example.test']);
    $urls = new ArrayObject;
    Notification::assertSentOnDemand(StaffInvitationMail::class, function (StaffInvitationMail $mail, array $channels, AnonymousNotifiable $to) use ($urls): bool {
        $urls[] = $mail->url;

        return true;
    });
    $invitation = $this->context->bypass(fn () => StaffInvitation::query()->sole());

    ($this->as)($this->owner, $this->tenants->a1)->delete(route('staff.invitations.destroy', $invitation->id))->assertRedirect(route('staff.index'));

    expect($this->context->bypass(fn () => StaffInvitation::query()->count()))->toBe(0);
    auth()->logout();
    $this->get((string) $urls[0])->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
});

it('cancels only this business\'s invitations, and only from its owner', function (string $other): void {
    $business = $this->tenants->{$other};
    $theirOwner = $this->tenants->member(User::factory()->create(), $business, BusinessRole::Owner);
    ($this->as)($theirOwner, $business)->post(route('staff.invitations.store'), ['email' => 'nadia@example.test']);
    $theirs = $this->context->bypass(fn () => StaffInvitation::query()->sole());

    ($this->as)($this->owner, $this->tenants->a1)->delete(route('staff.invitations.destroy', $theirs->id))->assertNotFound();
    ($this->as)($this->owner, $this->tenants->a1)->post(route('staff.invitations.store'), ['email' => 'omar@example.test']);
    $ours = $this->context->bypass(fn () => StaffInvitation::query()->where('email', 'omar@example.test')->sole());
    ($this->as)($this->staff, $this->tenants->a1)->delete(route('staff.invitations.destroy', $ours->id))->assertForbidden();

    expect($this->context->bypass(fn () => StaffInvitation::query()->count()))->toBe(2);
})->with(['franchisee A2' => ['a2'], 'organization B' => ['b1']]);

it('shows the staff page in the sidebar to the owner only', function (): void {
    ($this->as)($this->owner, $this->tenants->a1)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('nav.staff', true));
    ($this->as)($this->staff, $this->tenants->a1)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('nav.staff', false));
});
