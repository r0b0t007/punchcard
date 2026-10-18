<?php

declare(strict_types=1);

use App\Actions\Fraud\AlertCapStreak;
use App\Actions\Fraud\AlertDisabledStamperTap;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRequest;
use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\ReceiveTap;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Events\DailyCapReached;
use App\Listeners\SendFraudAlerts;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\Tap;
use App\Models\User;
use App\Notifications\DailyCapStreakMail;
use App\Notifications\StamperTappedWhileOffMail;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Fraud alerts to owners (CHW-36 part 2)
|--------------------------------------------------------------------------
|
| A business's owners, and only they, get an email when someone taps one of
| its switched-off stampers (at most one an hour per stamper), and when a
| customer reached a card's daily limit at the business three days in a
| row, each day in the site's own timezone. In the owner's language.
|
*/

beforeEach(function (): void {
    // 10:42 in Lagos (UTC+1 all year).
    Carbon::setTestNow('2026-10-17 09:42:00');
    app()->setLocale('en');
    Notification::fake();

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(['name' => 'Yasmine Alaoui']), $this->tenants->a1, BusinessRole::Owner);
    $this->staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->otherOwner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);
    $this->siblingOwner = $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner);
    $this->here = $this->tenants->locationOf($this->tenants->a1);
    $this->context->bypass(fn () => $this->here->forceFill(['name' => 'Hafa terrace', 'timezone' => 'Africa/Lagos'])->save());
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->context->bypass(fn () => $this->stamper->forceFill(['label' => 'Terrace bar'])->save());

    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);
    $this->receive = function (int $counter): Tap {
        $uid = $this->context->bypass(fn (): string => $this->stamper->tag()->firstOrFail()->uid);
        $url = app(FakeTap::class)->build($uid, $counter, 1);

        return app(ReceiveTap::class)->handle($url['e'], $url['c'], null, '203.0.113.7', 'Test phone');
    };
    $this->switchOff = fn () => $this->context->bypass(fn () => $this->stamper->forceFill(['status' => StamperStatus::Disabled])->save());
    $this->mail = fn (object $notification, User $to): array => (fn ($mail): array => [$mail->subject, ...$mail->introLines, ...$mail->outroLines])($notification->toMail($to));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('a switched-off stamper tapped', function (): void {
    it('tells the business\'s owners, and nobody else', function (): void {
        ($this->switchOff)();

        expect(($this->receive)(5)->rejection)->toBe(TapRejection::StamperDisabled);

        Notification::assertSentTo($this->owner, StamperTappedWhileOffMail::class, fn (StamperTappedWhileOffMail $mail): bool => ($this->mail)($mail, $this->owner) === [
            'Terrace bar was tapped while switched off',
            'Someone tapped Terrace bar at Hafa terrace today at 10:42, while it is switched off. The tap was refused: no stamp was given.',
            'If it went missing, keep it off. If you found it, switch it on again from your stampers page. You get this email at most once an hour per stamper.',
        ]);
        Notification::assertNotSentTo([$this->staff, $this->siblingOwner, $this->otherOwner], StamperTappedWhileOffMail::class);
    });

    it('sends at most one an hour per stamper', function (): void {
        ($this->switchOff)();
        ($this->receive)(5);
        ($this->receive)(6);

        Notification::assertSentToTimes($this->owner, StamperTappedWhileOffMail::class, 1);

        $this->travel(61)->minutes();
        ($this->receive)(7);

        Notification::assertSentToTimes($this->owner, StamperTappedWhileOffMail::class, 2);
    });

    it('says nothing when the stamper is on by the time the alert goes out', function (): void {
        ($this->switchOff)();
        $tap = ($this->receive)(5);
        Notification::fake();
        Cache::flush();
        $this->context->bypass(fn () => $this->stamper->forceFill(['status' => StamperStatus::Active])->save());

        app(AlertDisabledStamperTap::class)->handle($this->stamper->id, $tap->id);

        Notification::assertNothingSent();
    });

    it('stays quiet when the owner switched the stamper off after the tap', function (): void {
        $tap = ($this->receive)(5);
        ($this->switchOff)();

        expect(app(ApplyTap::class)->handle($tap, User::factory()->create())->rejection)->toBe(TapRejection::StamperDisabled);

        Notification::assertNothingSent();
    });

    it('stays quiet for a suspended business', function (): void {
        ($this->switchOff)();
        $this->context->bypass(fn () => $this->tenants->a1->forceFill(['status' => BusinessStatus::Suspended])->save());

        ($this->receive)(5);

        Notification::assertNothingSent();
    });

    it('owes the email again when it could not be sent', function (): void {
        ($this->switchOff)();
        $tap = $this->context->bypass(fn (): Tap => tap((new Tap)->forceFill(['nfc_tag_id' => $this->stamper->nfc_tag_id, 'stamper_id' => $this->stamper->id, 'business_id' => $this->stamper->business_id, 'status' => 'rejected', 'rejection' => TapRejection::StamperDisabled]))->save());
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('The mail server is down.'));

        expect(fn () => app(AlertDisabledStamperTap::class)->handle($this->stamper->id, $tap->id))->toThrow(RuntimeException::class)
            ->and(Cache::has('fraud-alert:stamper:'.$this->stamper->id.':'.$this->owner->id))->toBeFalse();
    });

    it('queues the alert, tried three times if the mail fails', function (): void {
        Queue::fake();
        ($this->switchOff)();

        ($this->receive)(5);

        Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === SendFraudAlerts::class
            && $job->tries === 3 && $job->backoff === [60, 300]);
    });

    it('stays quiet for a stamper that is on', function (): void {
        ($this->receive)(5);

        Notification::assertNothingSent();
    });

    it('writes in the owner\'s language, with the day when it was not today', function (): void {
        $this->context->bypass(fn () => $this->owner->forceFill(['locale' => 'fr'])->save());
        ($this->switchOff)();
        $tap = ($this->receive)(5);
        Notification::fake();
        $this->travel(1)->day();

        app(AlertDisabledStamperTap::class)->handle($this->stamper->id, $tap->id);

        Notification::assertSentTo($this->owner, StamperTappedWhileOffMail::class, function (StamperTappedWhileOffMail $mail, array $channels, User $to, ?string $locale): bool {
            app()->setLocale((string) $locale);

            return $locale === 'fr' && str_contains(($this->mail)($mail, $to)[1], '17 octobre à 10:42');
        });
    });
});

it('names a stamper with no label in the owner\'s language', function (): void {
    $this->context->bypass(fn () => $this->owner->forceFill(['locale' => 'fr'])->save());
    $this->context->bypass(fn () => $this->stamper->forceFill(['label' => null])->save());
    ($this->switchOff)();

    ($this->receive)(5);

    Notification::assertSentTo($this->owner, StamperTappedWhileOffMail::class, function (StamperTappedWhileOffMail $mail, array $channels, User $to, ?string $locale): bool {
        app()->setLocale((string) $locale);

        return ($this->mail)($mail, $to)[0] === 'Borne '.$this->stamper->id.' a été touchée alors qu’elle est désactivée';
    });
});

describe('the daily limit reached three days in a row', function (): void {
    beforeEach(function (): void {
        $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['name' => 'Hafa loyalty card', 'daily_cap' => 2, 'cooldown_min' => 0])->save());
        $this->context->bypass(fn () => $this->tenants->a1->forceFill(['name' => 'Café Hafa'])->save());
        $this->customer = User::factory()->create(['name' => 'Salma Bennani']);
        $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
        $this->a2Here = $this->tenants->locationOf($this->tenants->a2);
        $this->context->bypass(fn () => $this->a2Here->forceFill(['timezone' => 'Africa/Lagos'])->save());
        $this->stampAt = function (string $at, int $qty = 2, ?Location $where = null): void {
            Carbon::setTestNow($at);
            $where ??= $this->here;
            $staff = $where->is($this->here) ? $this->staff : $this->tenants->member(User::factory()->create(), $this->tenants->a2);
            $this->context->bypass(fn () => app(AddStamps::class)->handle(
                CardEnrollment::query()->findOrFail($this->enrollment->id),
                StampRequest::qr($where, $staff, (string) Str::uuid(), $qty),
            ));
        };
    });

    it('tells the business\'s owners on the third day, and nobody else', function (): void {
        ($this->stampAt)('2026-10-15 09:00:00');
        ($this->stampAt)('2026-10-16 09:00:00');
        Notification::assertNothingSent();

        ($this->stampAt)('2026-10-17 09:00:00');

        Notification::assertSentTo($this->owner, DailyCapStreakMail::class, fn (DailyCapStreakMail $mail): bool => ($this->mail)($mail, $this->owner) === [
            'Salma reached the daily limit 3 days in a row',
            'Salma reached the daily limit of Hafa loyalty card at Café Hafa 3 days in a row: 15, 16 and 17 October.',
            'It may be a loyal regular, or someone collecting stamps for others. If the limit is too high, whoever runs the card can lower it in its settings.',
        ]);
        Notification::assertNotSentTo([$this->staff, $this->siblingOwner, $this->otherOwner, $this->customer], DailyCapStreakMail::class);
    });

    it('counts only the days the limit was reached', function (): void {
        ($this->stampAt)('2026-10-15 09:00:00');
        ($this->stampAt)('2026-10-16 09:00:00', 1);
        ($this->stampAt)('2026-10-17 09:00:00');

        Notification::assertNothingSent();
    });

    it('counts only the days in a row', function (): void {
        ($this->stampAt)('2026-10-14 09:00:00');
        ($this->stampAt)('2026-10-15 09:00:00');
        ($this->stampAt)('2026-10-17 09:00:00');

        Notification::assertNothingSent();
    });

    it('counts only the days at this business', function (): void {
        ($this->stampAt)('2026-10-15 09:00:00', where: $this->a2Here);
        ($this->stampAt)('2026-10-16 09:00:00');
        ($this->stampAt)('2026-10-17 09:00:00');

        Notification::assertNothingSent();
    });

    it('counts the days in the site\'s own timezone', function (): void {
        // 23:30 UTC on the 14th is already the 15th in Lagos.
        ($this->stampAt)('2026-10-14 23:30:00');
        ($this->stampAt)('2026-10-16 09:00:00');
        ($this->stampAt)('2026-10-17 09:00:00');

        Notification::assertSentToTimes($this->owner, DailyCapStreakMail::class, 1);
    });

    it('sends one for a day, however often its job runs', function (): void {
        foreach (['2026-10-15', '2026-10-16', '2026-10-17'] as $day) {
            ($this->stampAt)($day.' 09:00:00');
        }

        app(AlertCapStreak::class)->handle($this->enrollment->id, $this->tenants->a1->id, '2026-10-17');

        Notification::assertSentToTimes($this->owner, DailyCapStreakMail::class, 1);
    });

    it('sends one per streak, however long it lasts, and one for the next streak', function (): void {
        foreach (['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-16', '2026-10-17', '2026-10-18'] as $day) {
            ($this->stampAt)($day.' 09:00:00', 1);
            ($this->stampAt)($day.' 10:00:00', 1);
        }

        Notification::assertSentToTimes($this->owner, DailyCapStreakMail::class, 2);
    });

    it('judges each day by the limit it had then', function (): void {
        // A limit of 5 on the 14th, 3 stamps: not full. Lowered to 2 from the 15th.
        $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['daily_cap' => 5])->save());
        ($this->stampAt)('2026-10-14 09:00:00', 3);
        $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['daily_cap' => 2])->save());
        ($this->stampAt)('2026-10-15 09:00:00');
        ($this->stampAt)('2026-10-16 09:00:00');
        ($this->stampAt)('2026-10-17 09:00:00');

        Notification::assertSentToTimes($this->owner, DailyCapStreakMail::class, 1);
    });

    it('completes a streak with a day filled late, whatever order the days come in', function (): void {
        foreach (['2026-10-15', '2026-10-17', '2026-10-16'] as $day) {
            app(AlertCapStreak::class)->handle($this->enrollment->id, $this->tenants->a1->id, $day);
        }

        Notification::assertSentTo($this->owner, DailyCapStreakMail::class, fn (DailyCapStreakMail $mail): bool => $mail->days === ['2026-10-15', '2026-10-16', '2026-10-17']);
        Notification::assertSentToTimes($this->owner, DailyCapStreakMail::class, 1);
    });

    it('mails again on a retry only the owners a failed send did not reach', function (): void {
        $second = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
        $mailed = new stdClass;
        $mailed->owners = [];
        Notification::shouldReceive('send')->andReturnUsing(function (User $owner) use ($second, $mailed): void {
            if ($owner->is($second)) {
                throw new RuntimeException('The mail server is down.');
            }

            $mailed->owners[] = $owner->id;
        });

        foreach (['2026-10-15', '2026-10-16'] as $day) {
            app(AlertCapStreak::class)->handle($this->enrollment->id, $this->tenants->a1->id, $day);
        }

        expect(fn () => app(AlertCapStreak::class)->handle($this->enrollment->id, $this->tenants->a1->id, '2026-10-17'))->toThrow(RuntimeException::class);

        // Owners go in order: the first was mailed, the second's send failed.
        expect($mailed->owners)->toContain($this->owner->id)->not->toContain($second->id);

        // The retry mails only the owner the failed send did not reach.
        Notification::fake();
        app(AlertCapStreak::class)->handle($this->enrollment->id, $this->tenants->a1->id, '2026-10-17');

        Notification::assertSentToTimes($second, DailyCapStreakMail::class, 1);
        Notification::assertNotSentTo($this->owner, DailyCapStreakMail::class);
    });

    it('sends one per streak when its first day is handled last', function (): void {
        foreach (['2026-10-16', '2026-10-17', '2026-10-18', '2026-10-15'] as $day) {
            app(AlertCapStreak::class)->handle($this->enrollment->id, $this->tenants->a1->id, $day);
        }

        Notification::assertSentToTimes($this->owner, DailyCapStreakMail::class, 1);
    });

    it('counts a day filled by a tap, an armed one given only the room left included, and never a refused one', function (): void {
        Event::fake([DailyCapReached::class]);
        $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->addMinute(), 'armed_by' => $this->staff->id])->save());

        expect(app(ApplyTap::class)->handle(($this->receive)(5), $this->customer)->qty)->toBe(2)
            ->and(app(ApplyTap::class)->handle(($this->receive)(6), $this->customer)->rejection)->toBe(TapRejection::DailyCap);

        Event::assertDispatchedTimes(DailyCapReached::class, 1);
        Event::assertDispatched(DailyCapReached::class, fn (DailyCapReached $event): bool => $event->date === '2026-10-17' && $event->businessId === $this->tenants->a1->id);
    });

    it('counts a stamp once, however often it is replayed', function (): void {
        Event::fake([DailyCapReached::class]);
        $request = StampRequest::qr($this->here, $this->staff, 'the-same-scan', 2);

        foreach ([1, 2] as $attempt) {
            $this->context->bypass(fn () => app(AddStamps::class)->handle(CardEnrollment::query()->findOrFail($this->enrollment->id), $request));
        }

        Event::assertDispatchedTimes(DailyCapReached::class, 1);
    });

    it('never turns a name into a link', function (string $name): void {
        $html = (string) (new DailyCapStreakMail($name, $name, $name, ['2026-10-15', '2026-10-16', '2026-10-17']))->toMail($this->owner)->render();

        expect($html)->not->toContain('href="https://phish.example');
    })->with(['brackets' => '[Verify](https://phish.example)', 'escaped brackets' => '\\[Verify\\](https://phish.example)', 'double backslash' => '\\\\[Verify\\\\](https://phish.example)']);
});
