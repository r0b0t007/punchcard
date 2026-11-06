<?php

declare(strict_types=1);

use App\Actions\Automations\RunAutomations;
use App\Actions\Automations\SendAutomationMessages;
use App\Actions\Customers\SummariseCustomer;
use App\Enums\AutomationTrigger;
use App\Enums\BusinessStatus;
use App\Enums\StampSource;
use App\Features\Automations;
use App\Jobs\PushAutomationMessages;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Notifications\AutomationMessage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The birthday bonus (CHW-46)
|--------------------------------------------------------------------------
|
| On a customer's birthday in the business's time, the automation's stamps
| on each card of the organization the business honours, once a year per
| card across the organization: in a franchise, the first business to reach
| them gives it. 29 February falls on 28 February of a year without one.
| The message follows for a follower with a phone, after a reward reminder
| in the weekly cap; the stamps never wait on it.
|
*/

beforeEach(function (): void {
    // 13:00 on 4 November in Casablanca, where the sites are.
    Carbon::setTestNow('2026-11-04 12:00:00');
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);
    Queue::fake([PushAutomationMessages::class]);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);

    $this->automation = function (AutomationTrigger $trigger = AutomationTrigger::Birthday, array $attributes = [], ?Business $business = null): Automation {
        $business ??= $this->tenants->a1;
        Feature::for($business)->activate(Automations::class);

        return $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $business->id, 'trigger' => $trigger, ...$attributes]));
    };

    // A customer of $at (a visit 20 days ago), born on 4 November unless told otherwise, following A with a phone.
    $this->customer = function (?Business $at = null, ?array $birthday = [4, 11], bool $follows = true, ?LoyaltyCard $card = null): User {
        $at ??= $this->tenants->a1;
        $card ??= $this->tenants->cardA;
        $customer = User::factory()->create(['birthday_day' => $birthday[0] ?? null, 'birthday_month' => $birthday[1] ?? null, 'locale' => 'fr']);
        $enrollment = $this->tenants->enroll($customer, $card);
        $this->context->bypass(fn () => (new Follow)->forceFill(['organization_id' => $card->organization_id, 'user_id' => $customer->id, 'notify' => $follows])->save());
        $this->tenants->stamp($enrollment, $at, ['created_at' => now()->subDays(20)]);
        app(SummariseCustomer::class)->handle($at->id, $at->organization_id, $customer->id);
        $customer->updatePushSubscription('https://fcm.googleapis.com/fcm/send/'.$customer->id, 'BKey', 'Secret');

        return $customer;
    };

    $this->run = fn (): array => app(RunAutomations::class)->handle(CarbonImmutable::now());
    // Birthday stamps given: [business, qty, key] each.
    $this->bonuses = fn (): array => $this->context->bypass(fn (): array => StampEvent::query()->where('source', StampSource::Birthday)->orderBy('id')->get()
        ->map(fn (StampEvent $stamp): array => [$stamp->business_id, $stamp->qty, $stamp->idempotency_key])->all());
    $this->sends = fn (): array => $this->context->bypass(fn (): array => AutomationSend::query()->orderBy('id')->get()
        ->map(fn (AutomationSend $send): array => [$send->business_id, $send->user_id, $send->trigger->value, $send->occasion])->all());
    $this->enrollmentOf = fn (User $customer, ?LoyaltyCard $card = null): CardEnrollment => $this->context->bypass(fn (): CardEnrollment => CardEnrollment::query()
        ->where('user_id', $customer->id)->where('card_id', ($card ?? $this->tenants->cardA)->id)->sole());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('gives the automation\'s stamps on their birthday, once a year, and claims the message', function (int $stamps): void {
    ($this->automation)(attributes: ['stamps' => $stamps]);
    $customer = ($this->customer)();
    $enrollment = ($this->enrollmentOf)($customer);
    $before = $enrollment->current_stamps;

    expect(($this->run)()['birthday_stamps'])->toBe(1);

    // The hour after, and again that day: nothing more.
    Carbon::setTestNow(now()->addHour());
    ($this->run)();
    Carbon::setTestNow(now()->addHours(5));
    ($this->run)();

    expect(($this->bonuses)())->toBe([[$this->tenants->a1->id, $stamps, 'birthday:'.$enrollment->id.':2026']])
        ->and($enrollment->refresh()->current_stamps - $before)->toBe($stamps)
        ->and(($this->sends)())->toBe([[$this->tenants->a1->id, $customer->id, 'birthday', 'birthday:2026']]);
})->with([1, 3]);

it('gives it on their birthday in the business\'s time, between 08:00 and 22:00, never the day before or after', function (string $timezone, string $local, bool $gives): void {
    $this->context->bypass(fn () => DB::table('locations')->where('business_id', $this->tenants->a1->id)->update(['timezone' => $timezone]));
    ($this->automation)();
    ($this->customer)();
    Carbon::setTestNow(CarbonImmutable::parse($local, $timezone)->utc());

    expect(($this->run)()['birthday_stamps'])->toBe($gives ? 1 : 0);
})->with([
    'Tokyo, 08:30 on the day (still the 3rd in UTC)' => ['Asia/Tokyo', '2026-11-04 08:30:00', true],
    'Tokyo, 22:30 on the day' => ['Asia/Tokyo', '2026-11-04 22:30:00', false],
    'Casablanca, the day before' => ['Africa/Casablanca', '2026-11-03 12:00:00', false],
    'Casablanca, the day after' => ['Africa/Casablanca', '2026-11-05 12:00:00', false],
]);

it('gives a 29 February birthday on 28 February of a year without one, and on the 29th of a leap year', function (string $local, bool $gives): void {
    ($this->automation)();
    ($this->customer)(birthday: [29, 2]);
    Carbon::setTestNow(CarbonImmutable::parse($local, 'Africa/Casablanca')->utc());

    expect(($this->run)()['birthday_stamps'])->toBe($gives ? 1 : 0);
})->with([
    '28 February 2027' => ['2027-02-28 12:00:00', true],
    '1 March 2027' => ['2027-03-01 12:00:00', false],
    '28 February 2028, a leap year' => ['2028-02-28 12:00:00', false],
    '29 February 2028' => ['2028-02-29 12:00:00', true],
]);

it('gives it again the next year', function (): void {
    ($this->automation)();
    $customer = ($this->customer)();
    $enrollment = ($this->enrollmentOf)($customer);
    ($this->run)();

    Carbon::setTestNow(now()->addYear());
    // A visit this year, so they are still A1's customer.
    $this->tenants->stamp($enrollment, $this->tenants->a1, ['created_at' => now()->subDays(20)]);
    ($this->run)();

    expect(array_column(($this->bonuses)(), 2))->toBe(['birthday:'.$enrollment->id.':2026', 'birthday:'.$enrollment->id.':2027']);
});

it('gives a franchise card one bonus a year, from the first business to reach it, and its message from that one only', function (): void {
    ($this->automation)();
    ($this->automation)(business: $this->tenants->a2);
    $customer = ($this->customer)();
    // A2's customer too.
    $this->tenants->stamp(($this->enrollmentOf)($customer), $this->tenants->a2, ['created_at' => now()->subDays(10)]);
    app(SummariseCustomer::class)->handle($this->tenants->a2->id, $this->tenants->orgA->id, $customer->id);

    ($this->run)();
    Carbon::setTestNow(now()->addHour());
    ($this->run)();

    expect(array_column(($this->bonuses)(), 0))->toBe([$this->tenants->a1->id])
        ->and(($this->sends)())->toBe([[$this->tenants->a1->id, $customer->id, 'birthday', 'birthday:2026']]);
});

it('gives nothing to someone anonymised, without a birthday, not a customer here, or of another organization', function (): void {
    ($this->automation)();
    ($this->automation)(business: $this->tenants->b1);
    ($this->customer)()->forceFill(['anonymised_at' => now()])->save();
    ($this->customer)(birthday: null);
    ($this->customer)(at: $this->tenants->a2);

    expect(($this->run)()['birthday_stamps'])->toBe(0)
        ->and(($this->bonuses)())->toBe([]);
});

it('keeps organization B\'s bonus to its own card and customers', function (): void {
    ($this->automation)(business: $this->tenants->b1);
    $atA = ($this->customer)();
    $atB = ($this->customer)(at: $this->tenants->b1, card: $this->tenants->cardB);

    ($this->run)();

    expect(($this->bonuses)())->toBe([[$this->tenants->b1->id, 1, 'birthday:'.($this->enrollmentOf)($atB, $this->tenants->cardB)->id.':2026']])
        ->and($atA->id)->not->toBe($atB->id);
});

it('skips a card that is paused, and gives the others', function (): void {
    ($this->automation)();
    $paused = ($this->customer)();
    $this->context->bypass(fn () => DB::table('loyalty_cards')->where('id', $this->tenants->cardA->id)->update(['active' => false]));
    // The same organization's other card, running at A1.
    $other = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create(['name' => 'Second card']);
        $card->businesses()->attach($this->tenants->a1->id);

        return $card;
    });
    $running = ($this->customer)(card: $other);

    expect(($this->run)()['birthday_stamps'])->toBe(1)
        ->and(($this->bonuses)())->toBe([[$this->tenants->a1->id, 1, 'birthday:'.($this->enrollmentOf)($running, $other)->id.':2026']])
        ->and($paused->id)->not->toBe($running->id);
});

it('unlocks the reward of a card the bonus completes', function (): void {
    ($this->automation)(attributes: ['stamps' => 3]);
    $customer = ($this->customer)();
    $enrollment = ($this->enrollmentOf)($customer);
    // Nine of ten: the visit's stamp and eight more.
    foreach (range(1, 8) as $visit) {
        $this->tenants->stamp($enrollment, $this->tenants->a1, ['created_at' => now()->subDays(20)->addMinutes($visit * 30)]);
    }
    $this->context->bypass(fn () => DB::table('card_enrollments')->where('id', $enrollment->id)->update(['current_stamps' => 9, 'lifetime_stamps' => 9]));

    ($this->run)();

    expect($this->context->bypass(fn (): int => Reward::query()->where('enrollment_id', $enrollment->id)->count()))->toBe(1)
        ->and($enrollment->refresh()->current_stamps)->toBe(2);
});

it('gives the stamps but sends no message to someone not following, and after a reward reminder in the weekly cap', function (): void {
    ($this->automation)();
    ($this->automation)(AutomationTrigger::RewardReminder, ['days' => 7]);
    $silent = ($this->customer)(follows: false);
    $both = ($this->customer)();
    $this->context->bypass(fn (): Reward => Reward::factory()->for(($this->enrollmentOf)($both), 'enrollment')->create([
        'stamp_event_id' => StampEvent::query()->where('enrollment_id', ($this->enrollmentOf)($both)->id)->value('id'),
        'unlocked_at' => now()->subDays(8),
    ]));

    ($this->run)();

    expect(($this->bonuses)())->toHaveCount(2)
        ->and(array_map(fn (array $send): array => [$send[1], $send[2]], ($this->sends)()))->toBe([[$both->id, 'reward_reminder']])
        ->and($silent->id)->not->toBe($both->id);
});

it('sends the message with the bonus in the customer\'s language, and nothing once it is no longer their birthday', function (string $locale, int $stamps, ?string $body): void {
    Notification::fake();
    ($this->automation)(attributes: ['stamps' => $stamps]);
    $customer = ($this->customer)();
    $customer->forceFill(['locale' => $locale])->save();
    ($this->run)();

    // Changed since the claim, within the hour: it is no longer their birthday.
    if ($body === null) {
        $customer->forceFill(['birthday_day' => 5, 'birthday_month' => 11])->save();
    }

    $previous = $this->context->snapshot();

    try {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);
        app(SendAutomationMessages::class)->handle($this->context->bypass(fn (): array => AutomationSend::query()->pluck('id')->all()));
    } finally {
        $this->context->restore($previous);
    }

    if ($body === null) {
        Notification::assertNothingSent();

        return;
    }

    Notification::assertSentTo($customer, AutomationMessage::class, function (AutomationMessage $message, array $channels, User $notifiable, ?string $sentIn) use ($body): bool {
        $previous = App::getLocale();
        App::setLocale((string) $sentIn);
        $push = $message->toWebPush($notifiable, $message)->toArray();
        App::setLocale($previous);

        return $push['body'] === $body;
    });
})->with([
    'French, 1 stamp' => ['fr', 1, '1 tampon offert sur votre carte.'],
    'French, 3 stamps' => ['fr', 3, '3 tampons offerts sur votre carte.'],
    'Arabic, 3 stamps' => ['ar', 3, '3 طوابع على بطاقتك، هدية منّا.'],
    'a birthday changed since' => ['fr', 1, null],
]);

it('leaves out a card the stamp is refused for, and gives the others', function (): void {
    ($this->automation)();
    $refused = ($this->customer)();
    $given = ($this->customer)();
    // Their account is deleted as the bonus takes their card's lock: AddStamps refuses it.
    $card = ($this->enrollmentOf)($refused)->id;
    $once = new stdClass;
    $once->done = false;
    DB::listen(function (QueryExecuted $query) use ($once, $refused, $card): void {
        if (! $once->done && str_contains($query->sql, 'from "card_enrollments"') && $query->bindings === [$card]) {
            $once->done = true;
            DB::table('users')->where('id', $refused->id)->update(['anonymised_at' => now()]);
        }
    });

    expect(($this->run)()['birthday_stamps'])->toBe(1)
        ->and(($this->bonuses)())->toBe([[$this->tenants->a1->id, 1, 'birthday:'.($this->enrollmentOf)($given)->id.':2026']]);
});

it('gives nothing from A1 once A2 gave the card its bonus this year, nor sends A1\'s message', function (): void {
    ($this->automation)();
    ($this->automation)(business: $this->tenants->a2);
    // A2's customer only, in the morning: A2 gives it.
    $customer = ($this->customer)(at: $this->tenants->a2);
    ($this->run)();

    // They come into A1 that day, and become its customer.
    Carbon::setTestNow(now()->addHour());
    $this->tenants->stamp(($this->enrollmentOf)($customer), $this->tenants->a1, ['created_at' => now()->subMinutes(5)]);
    app(SummariseCustomer::class)->handle($this->tenants->a1->id, $this->tenants->orgA->id, $customer->id);
    ($this->run)();

    expect(array_column(($this->bonuses)(), 0))->toBe([$this->tenants->a2->id])
        ->and(array_column(($this->sends)(), 0))->toBe([$this->tenants->a2->id]);
});

it('takes a bonus given elsewhere by its key, whatever the moment it was written', function (): void {
    ($this->automation)();
    $customer = ($this->customer)();
    $enrollment = ($this->enrollmentOf)($customer);
    // A2, hours ahead of A1, gave this year's bonus while it was still last year in A1's time.
    $this->tenants->stamp($enrollment, $this->tenants->a2, ['source' => StampSource::Birthday, 'idempotency_key' => 'birthday:'.$enrollment->id.':2026', 'created_at' => '2025-12-31 21:00:00']);

    expect(($this->run)()['birthday_stamps'])->toBe(0);
});

it('keys the bonus by the year it is in the business\'s time', function (): void {
    $this->context->bypass(fn () => DB::table('locations')->where('business_id', $this->tenants->a1->id)->update(['timezone' => 'Asia/Tokyo']));
    ($this->automation)();
    $customer = ($this->customer)(birthday: [1, 1]);
    // 08:30 on 1 January 2027 in Tokyo, still 2026 in UTC.
    Carbon::setTestNow(CarbonImmutable::parse('2027-01-01 08:30:00', 'Asia/Tokyo')->utc());

    ($this->run)();

    expect(array_column(($this->bonuses)(), 2))->toBe(['birthday:'.($this->enrollmentOf)($customer)->id.':2027']);
});

it('stamps each card honoured here once, never one honoured only by A2, and tells the bonus per card', function (): void {
    Notification::fake();
    ($this->automation)(attributes: ['stamps' => 3]);
    $customer = ($this->customer)();
    [$second, $a2s] = $this->context->bypass(function (): array {
        $second = LoyaltyCard::factory()->for($this->tenants->orgA)->create(['name' => 'Second card']);
        $second->businesses()->attach($this->tenants->a1->id);
        $a2s = LoyaltyCard::factory()->for($this->tenants->orgA)->create(['name' => 'A2 card']);
        $a2s->businesses()->attach($this->tenants->a2->id);

        return [$second, $a2s];
    });
    $this->tenants->enroll($customer, $second);
    $this->tenants->enroll($customer, $a2s);

    ($this->run)();

    expect(array_column(($this->bonuses)(), 2))->toEqualCanonicalizing([
        'birthday:'.($this->enrollmentOf)($customer)->id.':2026',
        'birthday:'.($this->enrollmentOf)($customer, $second)->id.':2026',
    ]);

    $this->context->set($this->tenants->orgA, $this->tenants->a1);
    app(SendAutomationMessages::class)->handle($this->context->bypass(fn (): array => AutomationSend::query()->pluck('id')->all()));
    $this->context->clear();

    Notification::assertSentTo($customer, AutomationMessage::class, fn (AutomationMessage $message): bool => $message->stamps === 3);
});

it('gives nothing while the birthday automation is off, or the business does not send', function (string $case): void {
    ($this->automation)(attributes: ['active' => $case !== 'birthday off']);
    ($this->automation)(AutomationTrigger::WinBack);
    ($this->customer)();

    match ($case) {
        'birthday off' => null,
        'suspended' => DB::table('businesses')->where('id', $this->tenants->a1->id)->update(['status' => BusinessStatus::Suspended->value]),
        'not allowed' => Feature::for($this->tenants->a1)->deactivate(Automations::class),
    };

    expect(($this->run)()['birthday_stamps'])->toBe(0)
        ->and(($this->bonuses)())->toBe([]);
})->with(['birthday off', 'suspended', 'not allowed']);

it('stamps only the card of the organization whose business gives it, for a customer of both', function (): void {
    ($this->automation)(business: $this->tenants->b1);
    $customer = ($this->customer)();
    $atB = $this->tenants->enroll($customer, $this->tenants->cardB);
    $this->context->bypass(fn () => (new Follow)->forceFill(['organization_id' => $this->tenants->orgB->id, 'user_id' => $customer->id, 'notify' => true])->save());
    $this->tenants->stamp($atB, $this->tenants->b1, ['created_at' => now()->subDays(20)]);
    app(SummariseCustomer::class)->handle($this->tenants->b1->id, $this->tenants->orgB->id, $customer->id);

    ($this->run)();

    expect(($this->bonuses)())->toBe([[$this->tenants->b1->id, 1, 'birthday:'.$atB->id.':2026']]);
});

it('reminds of a reward the bonus unlocked, as of one unlocked by a visit', function (): void {
    ($this->automation)(attributes: ['stamps' => 1]);
    ($this->automation)(AutomationTrigger::RewardReminder, ['days' => 7]);
    $customer = ($this->customer)();
    $enrollment = ($this->enrollmentOf)($customer);
    $this->context->bypass(fn () => DB::table('card_enrollments')->where('id', $enrollment->id)->update(['current_stamps' => 9, 'lifetime_stamps' => 9]));
    ($this->run)();
    // The birthday message delivered, as a phone accepting it would.
    $this->context->bypass(fn () => AutomationSend::query()->get()->each(fn (AutomationSend $send): bool => $send->markSent(now())));

    Carbon::setTestNow(now()->addDays(8));
    ($this->run)();

    expect(array_column(($this->sends)(), 2))->toBe(['birthday', 'reward_reminder']);
});
