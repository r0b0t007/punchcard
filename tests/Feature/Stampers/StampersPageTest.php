<?php

declare(strict_types=1);

use App\Actions\Stampers\RecordRekey;
use App\Actions\Stampers\SetStamperStatus;
use App\Enums\BusinessRole;
use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Http\Middleware\SetTenant;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The stampers page (CHW-36, B12)
|--------------------------------------------------------------------------
|
| The owner's page against abuse: each open site's stampers with their
| last tap and taps today, a switch to disable one at once (a tap on it is
| then refused) and enable it again, and the taps refused in the last 30
| days. The owner's or the org admin's, never staff's; never another
| business's stampers or taps, and a customer's first name only when the
| business may see them.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-17 10:00:00');
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->here = $this->tenants->locationOf($this->tenants->a1);

    $this->as = fn (User $user, ?Business $business = null) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.($business ?? $this->tenants->a1)->id]);
    $this->tap = fn (Stamper $stamper, string $at, TapStatus $status = TapStatus::Stamped, ?TapRejection $rejection = null, array $extra = []): Tap => $this->context->bypass(fn (): Tap => tap((new Tap)->forceFill([
        'nfc_tag_id' => $stamper->nfc_tag_id, 'stamper_id' => $stamper->id, 'business_id' => $stamper->business_id, 'location_id' => $stamper->location_id,
        'status' => $status, 'rejection' => $rejection, 'created_at' => Carbon::parse($at), 'ip' => '203.0.113.7', 'user_agent' => 'Test phone', ...$extra,
    ]))->save());
    $this->fresh = fn (Stamper $stamper): Stamper => $this->context->bypass(fn (): Stamper => Stamper::query()->findOrFail($stamper->id));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the owner each open site\'s stampers', function (): void {
    ($this->as)($this->owner)->get(route('stampers.index'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/stampers')
            ->has('sites', 1)
            ->where('sites.0.id', $this->here->id)
            ->where('sites.0.stampers.0.id', $this->stamper->id)
            ->where('sites.0.stampers.0.enabled', true));
});

it('keeps an org admin working across the organization off the page and the switch (no business chosen)', function (): void {
    $admin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    $this->actingAs($admin)->get(route('stampers.index'))->assertForbidden();
    $this->actingAs($admin)->patch(route('stampers.status', $this->stamper->id), ['status' => 'disabled'])->assertForbidden();

    expect(($this->fresh)($this->stamper)->status)->toBe(StamperStatus::Active);
});

it('keeps the page and the switch from staff', function (): void {
    ($this->as)($this->staff)->get(route('stampers.index'))->assertForbidden();
    ($this->as)($this->staff)->patch(route('stampers.status', $this->stamper->id), ['status' => 'disabled'])->assertForbidden();

    expect(($this->fresh)($this->stamper)->status)->toBe(StamperStatus::Active);
});

it('counts the last tap and today\'s taps in the site\'s own day, real taps only', function (): void {
    $this->context->bypass(fn () => $this->here->forceFill(['timezone' => 'Africa/Lagos'])->save());
    // Lagos is UTC+1 all year (Morocco's own offsets move with each tz database release): its day began at 2026-10-16 23:00 UTC.
    ($this->tap)($this->stamper, '2026-10-16 22:59:00');
    ($this->tap)($this->stamper, '2026-10-16 23:01:00');
    ($this->tap)($this->stamper, '2026-10-17 09:00:00', TapStatus::Rejected, TapRejection::Cooldown);
    ($this->tap)($this->stamper, '2026-10-17 09:30:00', TapStatus::Rejected, TapRejection::Replay);
    ($this->tap)($this->stamper, '2026-10-17 09:40:00', TapStatus::Rejected, TapRejection::BadMac);
    ($this->tap)($this->stamper, '2026-10-17 09:50:00', TapStatus::Rejected, TapRejection::Malformed);

    ($this->as)($this->owner)->get(route('stampers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('sites.0.timezone', 'Africa/Lagos')
            ->where('sites.0.stampers.0.tapsToday', 2)
            ->where('sites.0.stampers.0.lastTapAt', Carbon::parse('2026-10-17 09:00:00')->toIso8601String()));
});

it('lets the owner switch a stamper off at once, and on again', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 2, 'armed_until' => now()->addMinute(), 'armed_by' => $this->staff->id])->save());

    ($this->as)($this->owner)->patch(route('stampers.status', $this->stamper->id), ['status' => 'disabled'])
        ->assertRedirect(route('stampers.index'))
        ->assertInertiaFlash('toast.type', 'success');

    $disabled = ($this->fresh)($this->stamper);
    expect($disabled->status)->toBe(StamperStatus::Disabled)
        ->and($disabled->isArmed())->toBeFalse()
        ->and($disabled->armed_by)->toBeNull();

    ($this->as)($this->owner)->patch(route('stampers.status', $this->stamper->id), ['status' => 'disabled']);
    ($this->as)($this->owner)->patch(route('stampers.status', $this->stamper->id), ['status' => 'active']);

    expect(($this->fresh)($this->stamper)->status)->toBe(StamperStatus::Active)
        ->and($this->context->bypass(fn (): array => AuditLog::query()->where('action', 'like', 'stamper.%')->orderBy('id')->pluck('action')->all()))
        ->toBe(['stamper.disabled', 'stamper.enabled']);
});

it('switches only the business\'s own stampers', function (string $who): void {
    [$user, $business] = match ($who) {
        'a franchise sibling\'s owner' => [$this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner), $this->tenants->a2],
        'another organization\'s owner' => [$this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner), $this->tenants->b1],
    };

    ($this->as)($user, $business)->patch(route('stampers.status', $this->stamper->id), ['status' => 'disabled'])->assertNotFound();
    ($this->as)($user, $business)->get(route('stampers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('sites', fn ($sites): bool => collect($sites)->flatMap(fn ($site): mixed => $site['stampers'])->doesntContain('id', $this->stamper->id)));

    expect(($this->fresh)($this->stamper)->status)->toBe(StamperStatus::Active);
})->with(['a franchise sibling\'s owner', 'another organization\'s owner']);

it('refuses a status that is not one', function (): void {
    ($this->as)($this->owner)->patch(route('stampers.status', $this->stamper->id), ['status' => 'unassigned'])->assertSessionHasErrors('status');
});

it('lists the taps refused here in the last 30 days, newest first, without IP or user agent', function (): void {
    ($this->tap)($this->stamper, '2026-10-17 09:00:00', TapStatus::Rejected, TapRejection::Cooldown);
    ($this->tap)($this->stamper, '2026-10-16 18:00:00', TapStatus::Rejected, TapRejection::Replay);
    ($this->tap)($this->stamper, '2026-10-10 12:00:00', TapStatus::Rejected, TapRejection::StamperDisabled);
    // Not listed: older than 30 days, accepted, another business's.
    ($this->tap)($this->stamper, '2026-09-16 09:00:00', TapStatus::Rejected, TapRejection::Cooldown);
    ($this->tap)($this->stamper, '2026-10-17 08:00:00');
    ($this->tap)($this->tenants->stamper($this->tenants->a2), '2026-10-17 09:00:00', TapStatus::Rejected, TapRejection::DailyCap);
    ($this->tap)($this->tenants->stamper($this->tenants->b1), '2026-10-17 09:00:00', TapStatus::Rejected, TapRejection::DailyCap);

    ($this->as)($this->owner)->get(route('stampers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('refused.total', 3)
            ->where('refused.data.0.reason', 'cooldown')
            ->where('refused.data.0.tampering', false)
            ->where('refused.data.1.reason', 'replay')
            ->where('refused.data.1.tampering', true)
            ->where('refused.data.2.reason', 'stamper_disabled')
            ->where('refused.data.0.site', $this->here->name)
            ->missing('refused.data.0.ip')
            ->missing('refused.data.0.user_agent'));
});

it('pages the refused taps 25 at a time', function (): void {
    foreach (range(1, 27) as $minute) {
        ($this->tap)($this->stamper, '2026-10-17 08:'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).':00', TapStatus::Rejected, TapRejection::Cooldown);
    }

    ($this->as)($this->owner)->get(route('stampers.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('refused.total', 27)->has('refused.data', 2));
});

it('names a customer only when the business may see them', function (): void {
    $regular = User::factory()->create(['name' => 'Salma Bennani']);
    $this->tenants->stamp($this->tenants->enroll($regular, $this->tenants->cardA), $this->tenants->a1);
    // A franchise customer who only ever visited A2: A1 may not see them.
    $elsewhere = User::factory()->create(['name' => 'Nadia Tazi']);
    $this->tenants->stamp($this->tenants->enroll($elsewhere, $this->tenants->cardA), $this->tenants->a2);

    ($this->tap)($this->stamper, '2026-10-17 09:00:00', TapStatus::Rejected, TapRejection::Cooldown, ['user_id' => $regular->id, 'card_id' => $this->tenants->cardA->id]);
    ($this->tap)($this->stamper, '2026-10-17 08:00:00', TapStatus::Rejected, TapRejection::Cooldown, ['user_id' => $elsewhere->id, 'card_id' => $this->tenants->cardA->id]);
    ($this->tap)($this->stamper, '2026-10-17 07:00:00', TapStatus::Rejected, TapRejection::StamperDisabled, ['user_id' => $regular->id]);

    ($this->as)($this->owner)->get(route('stampers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('refused.data.0.customer', 'Salma')
            ->where('refused.data.1.customer', null)
            ->where('refused.data.2.customer', null));
});

it('shows the stampers page in the sidebar to whoever runs the business', function (): void {
    ($this->as)($this->owner)->get(route('stampers.index'))->assertInertia(fn (Assert $page): Assert => $page->where('nav.stampers', true));
    ($this->as)($this->staff)->get(route('counter.show'))->assertInertia(fn (Assert $page): Assert => $page->where('nav.stampers', false));
});

it('lists only open sites', function (): void {
    $closed = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['archived_at' => now()]));

    ($this->as)($this->owner)->get(route('stampers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('sites', fn ($sites): bool => collect($sites)->doesntContain('id', $closed->id)));
});

it('keeps a stamper the platform holds off until its keys are changed', function (): void {
    $uid = $this->context->bypass(fn (): string => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id)->uid);
    app(SetStamperStatus::class)->handle($uid, StamperStatus::Disabled);

    ($this->as)($this->owner)->get(route('stampers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('sites.0.stampers.0.enabled', false)->where('sites.0.stampers.0.held', true));
    ($this->as)($this->owner)->patch(route('stampers.status', $this->stamper->id), ['status' => 'active'])
        ->assertRedirect(route('stampers.index'))
        ->assertInertiaFlash('toast.type', 'error');

    expect(($this->fresh)($this->stamper)->status)->toBe(StamperStatus::Disabled)
        ->and($this->context->bypass(fn (): array => AuditLog::query()->where('action', 'stamper.enabled')->pluck('id')->all()))->toBe([]);

    // Recording the re-key releases the hold: the stamper stays off until its owner switches it on.
    app(RecordRekey::class)->handle($uid, from: 1);
    expect(($this->fresh)($this->stamper))->status->toBe(StamperStatus::Disabled)->held_at->toBeNull();

    ($this->as)($this->owner)->patch(route('stampers.status', $this->stamper->id), ['status' => 'active']);
    expect(($this->fresh)($this->stamper)->status)->toBe(StamperStatus::Active);
});

it('releases the hold when the platform switches the stamper on', function (): void {
    $uid = $this->context->bypass(fn (): string => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id)->uid);
    app(SetStamperStatus::class)->handle($uid, StamperStatus::Disabled);
    app(SetStamperStatus::class)->handle($uid, StamperStatus::Active);

    expect(($this->fresh)($this->stamper))->status->toBe(StamperStatus::Active)->held_at->toBeNull();
});

it('never lets a business write the platform\'s hold', function (): void {
    ($this->as)($this->owner)->get(route('stampers.index'));

    expect(fn () => Stamper::query()->current()->whereKey($this->stamper->id)->firstOrFail()->forceFill(['status' => StamperStatus::Disabled, 'held_at' => now()])->save())
        ->toThrow(LogicException::class);
});

it('names a refused tap\'s stamper by its number when it has no label', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['label' => null])->save());
    ($this->tap)($this->stamper, '2026-10-17 09:00:00', TapStatus::Rejected, TapRejection::Cooldown);

    ($this->as)($this->owner)->get(route('stampers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('refused.data.0.stamper', null)->where('refused.data.0.stamperId', $this->stamper->id));
});

it('counts each site\'s taps today in that site\'s own day', function (): void {
    // At 10:00 UTC: Lagos (UTC+1) began its day at 23:00 UTC yesterday, Tokyo (UTC+9) at 15:00 UTC yesterday.
    $this->context->bypass(fn () => $this->here->forceFill(['timezone' => 'Africa/Lagos'])->save());
    $tokyo = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['timezone' => 'Asia/Tokyo']));
    $there = $this->context->bypass(fn (): Stamper => Stamper::factory()->create(['business_id' => $this->tenants->a1->id, 'location_id' => $tokyo->id]));
    ($this->tap)($this->stamper, '2026-10-16 16:00:00');
    ($this->tap)($there, '2026-10-16 16:00:00');

    ($this->as)($this->owner)->get(route('stampers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('sites', function ($sites) use ($there): bool {
            $today = collect($sites)->flatMap(fn ($site): mixed => $site['stampers'])->pluck('tapsToday', 'id');

            return $today->all() === [$this->stamper->id => 0, $there->id => 1] || $today->all() === [$there->id => 1, $this->stamper->id => 0];
        }));
});

it('comes back to the refused taps page the owner was on', function (): void {
    ($this->as)($this->owner)->patch(route('stampers.status', $this->stamper->id), ['status' => 'disabled', 'page' => 3])
        ->assertRedirect(route('stampers.index', ['page' => 3]));
    ($this->as)($this->owner)->patch(route('stampers.status', $this->stamper->id), ['status' => 'active', 'page' => 1])
        ->assertRedirect(route('stampers.index'));
});
