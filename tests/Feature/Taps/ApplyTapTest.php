<?php

declare(strict_types=1);

use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\ReceiveTap;
use App\Actions\Taps\TapBelongsToAnotherCustomer;
use App\Actions\Tenancy\ArchiveLocation;
use App\Actions\Tenancy\ArchiveOrganization;
use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Events\EnrollmentChanged;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        ->and($tap->card_id)->toBe($this->tenants->cardA->id)
        ->and($tap->card_stamps)->toBe(1)
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
        ->and($cooldown->card_id)->toBe($this->tenants->cardA->id)
        ->and($cooldown->card_stamps)->toBeNull()
        ->and($replay->rejection)->toBe(TapRejection::Replay)
        ->and(($this->enrollment)()?->lifetime_stamps)->toBe(1);
});

it('refuses a tap whose stamper, site or card changed before it was applied, and enrolls nobody', function (string $change, TapRejection $rejection): void {
    $tap = ($this->tapAt)(5);
    $this->context->bypass(fn () => match ($change) {
        'the location archived meanwhile' => app(ArchiveLocation::class)->handle($this->tenants->locationOf($this->tenants->a1)),
        'the organization archived meanwhile' => app(ArchiveOrganization::class)->handle($this->tenants->orgA),
        'the business closed without its stampers ended' => $this->tenants->a1->forceFill(['archived_at' => now()])->save(),
        'the organization closed without its stampers ended' => $this->tenants->orgA->forceFill(['archived_at' => now()])->save(),
        'the card misconfigured' => DB::table('loyalty_cards')->where('id', $this->tenants->cardA->id)->update(['mode' => 'progressive', 'tiers' => '[]']),
        'the stamper paused meanwhile' => $this->stamper->forceFill(['status' => StamperStatus::Disabled])->save(),
        'the card switched off' => $this->tenants->cardA->forceFill(['active' => false])->save(),
        'the card no longer honoured here' => $this->tenants->cardA->businesses()->detach($this->tenants->a1->id),
    });

    $applied = ($this->apply)($tap);

    expect($applied->status)->toBe(TapStatus::Rejected)
        ->and($applied->rejection)->toBe($rejection)
        ->and($applied->user_id)->toBe($this->customer->id)
        ->and(($this->enrollment)())->toBeNull()
        ->and($this->context->bypass(fn (): int => StampEvent::query()->count()))->toBe(0);
})->with([
    'the location archived meanwhile' => ['the location archived meanwhile', TapRejection::UnassignedTag],
    'the organization archived meanwhile' => ['the organization archived meanwhile', TapRejection::UnassignedTag],
    'the business closed without its stampers ended' => ['the business closed without its stampers ended', TapRejection::SiteClosed],
    'the organization closed without its stampers ended' => ['the organization closed without its stampers ended', TapRejection::SiteClosed],
    'the card misconfigured' => ['the card misconfigured', TapRejection::CardMisconfigured],
    'the stamper paused meanwhile' => ['the stamper paused meanwhile', TapRejection::StamperDisabled],
    'the card switched off' => ['the card switched off', TapRejection::CardInactive],
    'the card no longer honoured here' => ['the card no longer honoured here', TapRejection::NotHonoured],
]);

it('refuses a tap whose stamper moved before it was applied: the stamp is given where the tap happened', function (): void {
    $tap = ($this->tapAt)(5);
    $this->context->bypass(fn () => $this->stamper->forceFill([
        'location_id' => Location::factory()->create(['business_id' => $this->tenants->a1->id])->id,
    ])->save());

    $applied = ($this->apply)($tap);

    expect($applied->rejection)->toBe(TapRejection::UnassignedTag)
        ->and($this->context->bypass(fn (): int => StampEvent::query()->count()))->toBe(0);
});

it('stamps a late tap when the stamps around it are outside the cooldown', function (): void {
    $signedOut = ($this->tapAt)(5);
    $this->travel(25)->minutes();
    ($this->apply)(($this->tapAt)(6));
    $this->travel(3)->minutes();

    expect(($this->apply)($signedOut)->status)->toBe(TapStatus::Stamped)
        ->and(($this->enrollment)()?->last_stamp_at?->toDateTimeString())->toBe('2026-10-05 10:25:00');
});

it('counts a late tap on its own day, not against the next day\'s stamps', function (): void {
    $this->context->bypass(function (): void {
        $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'UTC'])->save();
        $this->tenants->cardA->forceFill(['cooldown_min' => 0, 'daily_cap' => 1])->save();
    });
    Carbon::setTestNow('2026-10-05 23:50:00');
    $signedOut = ($this->tapAt)(5);
    Carbon::setTestNow('2026-10-06 00:05:00');
    ($this->apply)(($this->tapAt)(6));
    Carbon::setTestNow('2026-10-06 00:15:00');

    expect(($this->apply)($signedOut)->status)->toBe(TapStatus::Stamped);
});

it('refuses to run inside a caller\'s transaction', function (): void {
    $tap = ($this->tapAt)(5);

    DB::transaction(fn (): Tap => ($this->apply)($tap));
})->throws(LogicException::class, 'outside any transaction');

it('judges the cooldown at the tap\'s time, not at sign-in', function (): void {
    ($this->apply)(($this->tapAt)(5));
    $this->travel(2)->minutes();
    $signedOut = ($this->tapAt)(6);
    $this->travel(19)->minutes();

    $applied = ($this->apply)($signedOut);

    expect($applied->rejection)->toBe(TapRejection::Cooldown)
        ->and($applied->available_at?->toDateTimeString())->toBe('2026-10-05 10:21:00')
        ->and(($this->enrollment)()?->lifetime_stamps)->toBe(1);
});

it('never tells a late tap to come back at a time already past', function (): void {
    ($this->apply)(($this->tapAt)(5));
    $this->travel(2)->minutes();
    $signedOut = ($this->tapAt)(6);
    $this->travel(5)->minutes();

    expect(($this->apply)($signedOut)->available_at?->toDateTimeString())->toBe('2026-10-05 10:20:00');
});

it('stamps the card the customer already holds here, never a second one', function (): void {
    $newerCard = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create();
        $card->businesses()->attach($this->tenants->a1->id);

        return $card;
    });
    $held = $this->tenants->enroll($this->customer, $newerCard);

    $tap = ($this->apply)(($this->tapAt)(5));

    expect($this->context->bypass(fn (): int => (int) StampEvent::query()->whereKey($tap->stamp_event_id)->value('enrollment_id')))->toBe($held->id)
        ->and(($this->enrollment)())->toBeNull();
});

it('counts a tap before midnight on that day, even applied after it', function (): void {
    $this->context->bypass(function (): void {
        $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'UTC'])->save();
        $this->tenants->cardA->forceFill(['cooldown_min' => 0, 'daily_cap' => 1])->save();
    });
    Carbon::setTestNow('2026-10-05 23:55:00');
    ($this->apply)(($this->tapAt)(5));
    Carbon::setTestNow('2026-10-05 23:58:00');
    $signedOut = ($this->tapAt)(6);
    Carbon::setTestNow('2026-10-06 00:05:00');

    expect(($this->apply)($signedOut)->rejection)->toBe(TapRejection::DailyCap);
});

it('gives an armed tap the room left under the daily cap when it is applied', function (): void {
    $this->context->bypass(function (): void {
        $this->tenants->cardA->forceFill(['cooldown_min' => 0, 'daily_cap' => 3])->save();
        $this->stamper->forceFill(['armed_qty' => 4, 'armed_until' => now()->addSeconds(60)])->save();
    });

    $tap = ($this->apply)(($this->tapAt)(5));

    expect($tap->status)->toBe(TapStatus::Stamped)
        ->and($tap->qty)->toBe(3)
        ->and($this->context->bypass(fn (): int => (int) StampEvent::query()->whereKey($tap->stamp_event_id)->value('qty')))->toBe(3);
});

it('lets only the first customer claim a signed-out tap', function (): void {
    $tap = ($this->apply)(($this->tapAt)(5));

    ($this->apply)($tap, User::factory()->create());
})->throws(TapBelongsToAnotherCustomer::class, 'another customer');

it('lets a pending tap expire after 30 minutes', function (): void {
    $tap = ($this->tapAt)(5);
    $this->travel(31)->minutes();

    $expired = ($this->apply)($tap);

    expect($expired->status)->toBe(TapStatus::Expired)
        ->and($expired->rejection)->toBe(TapRejection::Expired)
        ->and($expired->user_id)->toBe($this->customer->id)
        ->and(($this->enrollment)())->toBeNull();
});

it('never lets another customer claim a tap someone received', function (): void {
    $tap = ($this->tapAt)(5, user: $this->customer);

    ($this->apply)($tap, User::factory()->create());
})->throws(TapBelongsToAnotherCustomer::class, 'another customer');

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
