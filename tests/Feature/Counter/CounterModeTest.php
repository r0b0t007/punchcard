<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Admin\SuspendBusiness;
use App\Actions\Counter\ArmStamper;
use App\Enums\BusinessRole;
use App\Enums\StamperStatus;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Counter mode (CHW-29, S1)
|--------------------------------------------------------------------------
|
| The screen staff keep open at the counter: each stamper they may arm,
| with its last tap, and "Next tap gives" +1 +2 +3 +5 for ArmStamper::SECONDS.
| The next verified tap on that stamper gives that many (once), then it
| gives one again; "Cancel" ends it sooner. Whoever works in the business
| (owner or staff), staff limited to one location at that location only;
| never another business's stamper.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-16 09:00:00');
    app()->setLocale('en');
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);

    $this->as = fn (User $user, ?Business $business = null) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.($business ?? $this->tenants->a1)->id]);
    $this->arm = fn (User $user, Stamper $stamper, mixed $qty) => ($this->as)($user)->post(route('counter.arm', $stamper->id), ['qty' => $qty]);
    $this->fresh = fn (Stamper $stamper): Stamper => $this->context->bypass(fn (): Stamper => Stamper::query()->findOrFail($stamper->id));
    // A real tap on the stamper, as its tag writes it, by a signed-in customer: the stamps it gave.
    $this->tap = function (Stamper $stamper, int $counter): int {
        $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($stamper->nfc_tag_id));
        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('taps.receive', app(FakeTap::class)->build($tag->uid, $counter)));

        return (int) $this->context->bypass(fn (): mixed => StampEvent::query()->where('stamper_id', $stamper->id)->latest('id')->value('qty'));
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the owner and staff the stampers they may arm, with each one\'s last tap', function (string $who): void {
    ($this->tap)($this->stamper, 1);
    $this->travel(1)->minutes();

    ($this->as)($this->{$who})->get(route('counter.show'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/counter')
            ->has('stampers', 1)
            ->where('stampers.0.id', $this->stamper->id)
            ->where('stampers.0.enabled', true)
            ->where('stampers.0.armedQty', null)
            ->where('stampers.0.lastTapAt', now()->subMinute()->toIso8601String())
            ->where('armSeconds', ArmStamper::SECONDS));
})->with(['owner', 'staff']);

it('keeps customers and an org admin across the organization off the counter', function (): void {
    $this->actingAs(User::factory()->create())->get(route('counter.show'))->assertForbidden();

    $admin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->actingAs($admin)->get(route('counter.show'))->assertForbidden();
});

it('arms a stamper for the next tap: it gives that many once, then one again', function (): void {
    ($this->arm)($this->staff, $this->stamper, 3)->assertRedirect(route('counter.show'));

    expect(($this->fresh)($this->stamper))
        ->armed_qty->toBe(3)
        ->armed_until->toEqual(now()->addSeconds(ArmStamper::SECONDS));

    expect(($this->tap)($this->stamper, 1))->toBe(3)
        ->and(($this->fresh)($this->stamper)->armed_qty)->toBeNull()
        ->and(($this->tap)($this->stamper, 2))->toBe(1);
});

it('lets an arming run out after its seconds', function (): void {
    ($this->arm)($this->staff, $this->stamper, 5);
    $this->travel(ArmStamper::SECONDS + 1)->seconds();

    expect(($this->tap)($this->stamper, 1))->toBe(1);
});

it('cancels an arming, and a new one replaces it for the full time', function (): void {
    ($this->arm)($this->staff, $this->stamper, 5);
    ($this->as)($this->staff)->delete(route('counter.disarm', $this->stamper->id))->assertRedirect(route('counter.show'));
    expect(($this->fresh)($this->stamper)->armed_qty)->toBeNull();

    ($this->arm)($this->staff, $this->stamper, 2);
    $this->travel(30)->seconds();
    ($this->arm)($this->staff, $this->stamper, 5);

    expect(($this->fresh)($this->stamper))
        ->armed_qty->toBe(5)
        ->armed_until->toEqual(now()->addSeconds(ArmStamper::SECONDS));
});

it('offers +1, +2, +3 and +5 only', function (mixed $qty): void {
    ($this->arm)($this->staff, $this->stamper, $qty)->assertSessionHasErrors('qty');

    expect(($this->fresh)($this->stamper)->armed_qty)->toBeNull();
})->with([0, 4, 11, 'two']);

it('never arms a disabled stamper', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['status' => StamperStatus::Disabled])->save());

    ($this->arm)($this->staff, $this->stamper, 3)->assertRedirect(route('counter.show'))->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This stamper can\'t be armed now: it is off, or the site is closed.']);

    expect(($this->fresh)($this->stamper)->armed_qty)->toBeNull();
});

it('lets staff limited to one location arm only there, and see only its stampers', function (): void {
    $terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['name' => 'A1 terrace']));
    $terraceStamper = $this->context->bypass(fn (): Stamper => Stamper::factory()->create(['business_id' => $this->tenants->a1->id, 'location_id' => $terrace->id]));
    $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $this->staff->id)->update(['location_id' => $terrace->id]));

    ($this->as)($this->staff)->get(route('counter.show'))
        ->assertInertia(fn (Assert $page): Assert => $page->has('stampers', 1)->where('stampers.0.id', $terraceStamper->id));
    ($this->arm)($this->staff, $this->stamper, 3)->assertForbidden();
    ($this->arm)($this->staff, $terraceStamper, 3)->assertRedirect();

    expect(($this->fresh)($this->stamper)->armed_qty)->toBeNull()
        ->and(($this->fresh)($terraceStamper)->armed_qty)->toBe(3);
});

it('never arms or cancels another business\'s stamper (both boundaries)', function (string $other): void {
    $theirs = $this->tenants->stamper($this->tenants->{$other});
    $this->context->bypass(fn () => $theirs->forceFill(['armed_qty' => 2, 'armed_until' => now()->addMinute()])->save());

    ($this->arm)($this->owner, $theirs, 5)->assertNotFound();
    ($this->as)($this->owner)->delete(route('counter.disarm', $theirs->id))->assertNotFound();

    expect(($this->fresh)($theirs)->armed_qty)->toBe(2);
})->with(['franchisee A2' => ['a2'], 'organization B' => ['b1']]);

it('opens the counter for staff instead of an empty dashboard, and lists it in the sidebar', function (): void {
    ($this->as)($this->staff)->get(route('dashboard'))->assertRedirect(route('counter.show'));
    ($this->as)($this->owner)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('nav.counter', true));
});

it('names who armed it on the stamp the armed tap gives, and nobody on an ordinary tap', function (): void {
    ($this->arm)($this->staff, $this->stamper, 3);
    ($this->tap)($this->stamper, 1);
    ($this->tap)($this->stamper, 2);

    $stamps = $this->context->bypass(fn () => StampEvent::query()->where('stamper_id', $this->stamper->id)->orderBy('id')->get());
    expect($stamps->pluck('qty')->all())->toBe([3, 1])
        ->and($stamps->pluck('staff_id')->all())->toBe([$this->staff->id, null]);
});

it('never arms at a site closed or a business suspended, and a suspension ends an arming', function (): void {
    ($this->arm)($this->staff, $this->stamper, 3);
    app(SuspendBusiness::class)->handle($this->tenants->a1, 'Unpaid');

    expect(($this->fresh)($this->stamper)->armed_qty)->toBeNull()
        ->and(($this->fresh)($this->stamper)->armed_by)->toBeNull();

    $this->context->bypass(fn () => Business::query()->whereKey($this->tenants->a1->id)->update(['status' => 'active']));
    $this->context->bypass(fn () => Location::query()->whereKey($this->stamper->location_id)->update(['archived_at' => now()]));
    expect(app(ArmStamper::class)->handle($this->stamper, 3, $this->staff))->toBeFalse()
        ->and(($this->fresh)($this->stamper)->armed_qty)->toBeNull();
});

it('never arms a stamper whose assignment ended', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['unassigned_at' => now()])->save());

    ($this->arm)($this->owner, $this->stamper, 3)->assertNotFound();
});

it('gives one stamp to a tap received before the arming, and keeps the arming for the next', function (): void {
    $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id));
    // Signed out: the tap waits in the session, with what it gives already decided.
    auth()->logout();
    $this->get(route('taps.receive', app(FakeTap::class)->build($tag->uid, 1)));
    ($this->arm)($this->staff, $this->stamper, 5);
    auth()->logout();

    $this->actingAs(User::factory()->create())->get(route('taps.claim'));

    expect($this->context->bypass(fn (): mixed => StampEvent::query()->where('stamper_id', $this->stamper->id)->value('qty')))->toBe(1)
        ->and(($this->fresh)($this->stamper)->armed_qty)->toBe(5);
});

it('cancels quietly an arming a tap already took', function (): void {
    ($this->arm)($this->staff, $this->stamper, 3);
    ($this->tap)($this->stamper, 1);

    ($this->as)($this->staff)->delete(route('counter.disarm', $this->stamper->id))->assertRedirect(route('counter.show'));

    expect(($this->fresh)($this->stamper)->armed_qty)->toBeNull();
});

it('lets staff limited to one location cancel only there', function (): void {
    $terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['name' => 'A1 terrace']));
    ($this->arm)($this->owner, $this->stamper, 3);
    $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $this->staff->id)->update(['location_id' => $terrace->id]));

    ($this->as)($this->staff)->delete(route('counter.disarm', $this->stamper->id))->assertForbidden();

    expect(($this->fresh)($this->stamper)->armed_qty)->toBe(3);
});

it('limits arming to the counter\'s pace', function (): void {
    foreach (range(1, 60) as $attempt) {
        ($this->arm)($this->staff, $this->stamper, 1)->assertRedirect();
    }

    ($this->arm)($this->staff, $this->stamper, 1)->assertTooManyRequests();
});

it('shows as the last tap one still waiting for sign-in, never a forged URL', function (): void {
    $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id));
    $this->get(route('taps.receive', app(FakeTap::class)->build($tag->uid, 1)));
    $this->travel(10)->seconds();
    // A replayed URL: the same counter again.
    $this->get(route('taps.receive', app(FakeTap::class)->build($tag->uid, 1)));

    ($this->as)($this->staff)->get(route('counter.show'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('stampers.0.lastTapAt', now()->subSeconds(10)->toIso8601String()));
});

it('ends the armings of a staff member taken off the staff', function (): void {
    ($this->arm)($this->staff, $this->stamper, 5);
    $membership = $this->context->bypass(fn (): BusinessMember => BusinessMember::query()->where('user_id', $this->staff->id)->sole());

    ($this->as)($this->owner)->delete(route('staff.destroy', $membership->id))->assertRedirect(route('staff.index'));

    expect(($this->tap)($this->stamper, 1))->toBe(1);
});

it('keeps the dashboard for an org admin who is also staff there', function (): void {
    $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->tenants->member($hq, $this->tenants->a1);

    ($this->as)($hq)->get(route('dashboard'))->assertOk();
});

it('never pays an arming to whoever made it: their own tap gives one stamp', function (): void {
    ($this->arm)($this->staff, $this->stamper, 5);
    $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id));

    $this->actingAs($this->staff)->get(route('taps.receive', app(FakeTap::class)->build($tag->uid, 1)));

    $stamp = $this->context->bypass(fn (): StampEvent => StampEvent::query()->where('stamper_id', $this->stamper->id)->sole());
    expect($stamp->qty)->toBe(1)
        ->and($stamp->staff_id)->toBeNull();
});

it('gives one stamp for an arming whose armer no longer works there', function (string $how): void {
    ($this->arm)($this->staff, $this->stamper, 5);

    match ($how) {
        'account deleted' => app(DeleteAccount::class)->handle($this->staff),
        'limited to another location' => $this->context->bypass(function (): void {
            $terrace = Location::factory()->for($this->tenants->a1)->create(['name' => 'A1 terrace']);
            BusinessMember::query()->where('user_id', $this->staff->id)->update(['location_id' => $terrace->id]);
        }),
    };

    expect(($this->tap)($this->stamper, 1))->toBe(1);
})->with(['account deleted', 'limited to another location']);

it('names nobody on a tap whose armer\'s account went before it was claimed', function (): void {
    ($this->arm)($this->staff, $this->stamper, 5);
    $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id));
    auth()->logout();
    $this->get(route('taps.receive', app(FakeTap::class)->build($tag->uid, 1)));
    $this->context->bypass(fn () => $this->staff->delete());

    $this->actingAs(User::factory()->create())->get(route('taps.claim'))->assertRedirect(route('taps.result'));

    $stamp = $this->context->bypass(fn (): StampEvent => StampEvent::query()->where('stamper_id', $this->stamper->id)->sole());
    expect($stamp->qty)->toBe(5)
        ->and($stamp->staff_id)->toBeNull();
});

it('checks the location limit again on the locked stamper', function (): void {
    $terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['name' => 'A1 terrace']));
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff, locationId: $terrace->id);

    expect(app(ArmStamper::class)->handle($this->stamper, 3, $this->staff))->toBeFalse()
        ->and(($this->fresh)($this->stamper)->armed_qty)->toBeNull();
});
