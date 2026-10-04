<?php

declare(strict_types=1);

use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\ReceiveTap;
use App\Actions\Tenancy\ArchiveLocation;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Events\EnrollmentChanged;
use App\Models\CardEnrollment;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Applying a tap (CHW-25)
|--------------------------------------------------------------------------
|
| ApplyTap turns a pending tap into a stamp once the customer is known: it
| enrolls them on the business's card, stamps through AddStamps in the
| stamper's tenant, and records the outcome on the tap. A refusal is
| recorded, never thrown; the counter stays spent either way.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 10:00:00');
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->customer = User::factory()->create();

    $this->tapAt = function (int $counter, ?Stamper $stamper = null, ?User $user = null): Tap {
        $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail(($stamper ?? $this->stamper)->nfc_tag_id));
        $url = app(FakeTap::class)->build($tag->uid, $counter);

        return app(ReceiveTap::class)->handle($url['e'], $url['c'], $user, '203.0.113.7', 'Test phone');
    };
    $this->apply = fn (Tap $tap, ?User $user = null): Tap => app(ApplyTap::class)->handle($tap, $user ?? $this->customer);
    $this->enrollment = fn (?User $user = null): ?CardEnrollment => $this->context->bypass(fn (): ?CardEnrollment => CardEnrollment::query()
        ->where('card_id', $this->tenants->cardA->id)
        ->where('user_id', ($user ?? $this->customer)->id)
        ->first());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('enrolls the customer on the business\'s card and stamps, once', function (): void {
    $tap = ($this->apply)(($this->tapAt)(5));
    $again = ($this->apply)($tap);

    expect($tap->status)->toBe(TapStatus::Stamped)
        ->and($tap->user_id)->toBe($this->customer->id)
        ->and($tap->stamp_event_id)->not->toBeNull()
        ->and($again->status)->toBe(TapStatus::Stamped)
        ->and(($this->enrollment)()?->lifetime_stamps)->toBe(1)
        ->and(($this->enrollment)()?->referral_code)->toMatch('/^[A-Z2-9]{8}$/')
        ->and($this->context->bypass(fn (): int => StampEvent::query()->count()))->toBe(1);
});

it('records a refusal on the tap, and the counter stays spent', function (): void {
    ($this->apply)(($this->tapAt)(5));

    $cooldown = ($this->apply)(($this->tapAt)(6));
    $replay = ($this->tapAt)(6);

    expect($cooldown->status)->toBe(TapStatus::Rejected)
        ->and($cooldown->rejection)->toBe(TapRejection::Cooldown)
        ->and($cooldown->available_at?->toDateTimeString())->toBe('2026-10-05 10:20:00')
        ->and($replay->rejection)->toBe(TapRejection::Replay)
        ->and(($this->enrollment)()?->lifetime_stamps)->toBe(1);
});

it('maps the stamp refusals a tap can hit', function (string $change, TapRejection $rejection): void {
    $tap = ($this->tapAt)(5);
    $this->context->bypass(fn () => match ($change) {
        'the location archived meanwhile' => app(ArchiveLocation::class)->handle($this->tenants->locationOf($this->tenants->a1)),
        'the card switched off' => $this->tenants->cardA->forceFill(['active' => false])->save(),
        'the card no longer honoured here' => $this->tenants->cardA->businesses()->detach($this->tenants->a1->id),
    });

    $applied = ($this->apply)($tap);

    expect($applied->status)->toBe(TapStatus::Rejected)
        ->and($applied->rejection)->toBe($rejection)
        ->and($this->context->bypass(fn (): int => StampEvent::query()->count()))->toBe(0);
})->with([
    'the location archived meanwhile' => ['the location archived meanwhile', TapRejection::SiteClosed],
    'the card switched off' => ['the card switched off', TapRejection::NotHonoured],
    'the card no longer honoured here' => ['the card no longer honoured here', TapRejection::NotHonoured],
]);

it('lets a pending tap expire after 30 minutes', function (): void {
    $tap = ($this->tapAt)(5);
    $this->travel(31)->minutes();

    $expired = ($this->apply)($tap);

    expect($expired->status)->toBe(TapStatus::Expired)
        ->and($expired->rejection)->toBe(TapRejection::Expired)
        ->and(($this->enrollment)())->toBeNull();
});

it('never lets another customer claim a tap someone received', function (): void {
    $tap = ($this->tapAt)(5, user: $this->customer);

    ($this->apply)($tap, User::factory()->create());
})->throws(LogicException::class, 'another customer');

it('ignores a tap that was refused when received', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['unassigned_at' => now()])->save());
    $tap = ($this->tapAt)(5);

    expect(($this->apply)($tap)->status)->toBe(TapStatus::Rejected)
        ->and(($this->enrollment)())->toBeNull();
});

it('stamps the card of the business that holds the stamper, never another organization\'s', function (): void {
    $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $b1Stamper = $this->tenants->stamper($this->tenants->b1);

    $tap = ($this->apply)(($this->tapAt)(5, $b1Stamper));

    $event = $this->context->bypass(fn (): StampEvent => StampEvent::query()->findOrFail($tap->stamp_event_id));
    $cardId = $this->context->bypass(fn (): int => (int) CardEnrollment::query()->whereKey($event->enrollment_id)->value('card_id'));

    expect($event->business_id)->toBe($this->tenants->b1->id)
        ->and($cardId)->toBe($this->tenants->cardB->id)
        ->and(($this->enrollment)()?->lifetime_stamps)->toBe(0);
});

it('runs in the stamper\'s tenant, so queued listeners know it, and leaves the request as it was', function (): void {
    $seen = [];
    Event::listen(EnrollmentChanged::class, function () use (&$seen): void {
        $seen[] = [app(TenantContext::class)->organizationId(), app(TenantContext::class)->businessId(), app(TenantContext::class)->businessRole()];
    });

    ($this->apply)(($this->tapAt)(5));

    expect($seen)->toBe([[$this->tenants->orgA->id, $this->tenants->a1->id, null]])
        ->and($this->context->businessId())->toBeNull();
});
