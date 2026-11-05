<?php

declare(strict_types=1);

use App\Actions\Automations\RunAutomations;
use App\Actions\Customers\SummariseCustomer;
use App\Enums\AutomationTrigger;
use App\Enums\BusinessStatus;
use App\Enums\RewardStatus;
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
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The automation engine (CHW-46)
|--------------------------------------------------------------------------
|
| Hourly: for each business allowed automations, between 08:00 and 22:00 in
| its own time, a reward reminder to whoever left a reward unused here, a
| win-back to a follower going quiet here. Once per occasion, at most one
| message per customer per business a week, a reward reminder first. Never
| a customer without a phone, an anonymised account, a sister franchisee's
| customer.
|
*/

beforeEach(function (): void {
    // 13:00 in Casablanca, where the sites are.
    Carbon::setTestNow('2026-11-04 12:00:00');
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);
    Queue::fake([PushAutomationMessages::class]);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    Feature::for($this->tenants->a1)->activate(Automations::class);

    $this->automation = fn (AutomationTrigger $trigger, array $attributes = [], ?Business $business = null): Automation => $this->context->bypass(fn (): Automation => Automation::factory()
        ->create(['business_id' => ($business ?? $this->tenants->a1)->id, 'trigger' => $trigger, ...$attributes]));

    // A customer of A1 who came in 20 days ago: going quiet (quiet_min_days 14, lost_days 60).
    $this->customer = function (bool $follows = true, bool $phone = true, ?Business $at = null, int $daysAgo = 20, bool $pushRewards = true, ?LoyaltyCard $card = null): User {
        $at ??= $this->tenants->a1;
        $card ??= $this->tenants->cardA;
        $customer = User::factory()->create(['push_rewards' => $pushRewards]);
        $enrollment = $this->tenants->enroll($customer, $card);
        $this->context->bypass(fn () => (new Follow)->forceFill(['organization_id' => $card->organization_id, 'user_id' => $customer->id, 'notify' => $follows])->save());
        $this->tenants->stamp($enrollment, $at, ['created_at' => now()->subDays($daysAgo)]);
        app(SummariseCustomer::class)->handle($at->id, $at->organization_id, $customer->id);

        if ($phone) {
            $customer->updatePushSubscription('https://fcm.googleapis.com/fcm/send/'.$customer->id, 'BKey', 'Secret');
        }

        return $customer;
    };

    // A reward the customer unlocked $daysAgo, with a stamp given at $at.
    $this->reward = function (User $customer, int $daysAgo = 10, ?Business $at = null, RewardStatus $status = RewardStatus::Available, int $milestone = 1): Reward {
        $enrollment = $this->context->bypass(fn (): CardEnrollment => CardEnrollment::query()->where('user_id', $customer->id)->firstOrFail());
        $stamp = $this->tenants->stamp($enrollment, $at ?? $this->tenants->a1, ['created_at' => now()->subDays($daysAgo)]);

        return $this->context->bypass(fn (): Reward => Reward::factory()->for($enrollment, 'enrollment')
            ->create(['stamp_event_id' => $stamp->id, 'unlocked_at' => now()->subDays($daysAgo), 'status' => $status, 'milestone' => $milestone,
                ...($status === RewardStatus::Redeemed ? ['redeemed_at' => now(), 'redeemed_business_id' => $stamp->business_id] : [])]));
    };

    $this->run = fn (): array => app(RunAutomations::class)->handle(CarbonImmutable::now());
    $this->sends = fn (): array => $this->context->bypass(fn (): array => AutomationSend::query()->orderBy('id')->get()
        ->map(fn (AutomationSend $send): array => [$send->user_id, $send->trigger->value, $send->occasion])->all());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('sends a win-back to a follower going quiet here, once per quiet spell, queued in the business\'s tenant', function (): void {
    ($this->automation)(AutomationTrigger::WinBack);
    $customer = ($this->customer)();
    $quietAt = $this->context->bypass(fn () => DB::table('business_customers')->where('user_id', $customer->id)->value('quiet_at'));

    expect(($this->run)())->toBe(['businesses' => 1, 'claimed' => 1])
        ->and(($this->sends)())->toBe([[$customer->id, 'win_back', 'quiet:'.CarbonImmutable::parse($quietAt)->toDateString()]]);

    Queue::assertPushed(PushAutomationMessages::class, 1);

    // The hour after, and a week after (past the cap): the same spell is not sent again.
    ($this->run)();
    Carbon::setTestNow(now()->addDays(8));
    ($this->run)();

    expect(($this->sends)())->toHaveCount(1);
});

it('sends a win-back again for a new quiet spell, after a visit', function (): void {
    ($this->automation)(AutomationTrigger::WinBack);
    $customer = ($this->customer)();
    ($this->run)();

    // They came back the next day (a usual gap of 21 days), then went quiet again after twice that.
    Carbon::setTestNow(now()->addDay());
    $enrollment = $this->context->bypass(fn (): CardEnrollment => CardEnrollment::query()->where('user_id', $customer->id)->firstOrFail());
    $this->tenants->stamp($enrollment, $this->tenants->a1);
    app(SummariseCustomer::class)->handle($this->tenants->a1->id, $this->tenants->orgA->id, $customer->id);
    ($this->run)();

    expect(($this->sends)())->toHaveCount(1);

    Carbon::setTestNow(now()->addDays(43));
    ($this->run)();

    expect(array_column(($this->sends)(), 2))->toHaveCount(2)->each->toStartWith('quiet:')
        ->and(count(array_unique(array_column(($this->sends)(), 2))))->toBe(2);
});

it('never sends a win-back to someone not following, without a phone, anonymised, not yet quiet, or only A2\'s customer', function (): void {
    ($this->automation)(AutomationTrigger::WinBack);
    ($this->customer)(follows: false);
    ($this->customer)(phone: false);
    ($this->customer)(daysAgo: 3);
    ($this->customer)(at: $this->tenants->a2);
    ($this->customer)()->forceFill(['anonymised_at' => now()])->save();

    expect(($this->run)()['claimed'])->toBe(0)
        ->and(($this->sends)())->toBe([]);
});

it('reminds of a reward unused here for the automation\'s days, once, never one used, expired, unlocked elsewhere or with rewards ready off', function (): void {
    ($this->automation)(AutomationTrigger::RewardReminder, ['days' => 7]);
    $waiting = ($this->customer)(daysAgo: 3);
    $reward = ($this->reward)($waiting, daysAgo: 7);
    ($this->reward)(($this->customer)(daysAgo: 3), daysAgo: 6);
    ($this->reward)(($this->customer)(daysAgo: 3), status: RewardStatus::Redeemed);
    $expired = ($this->reward)(($this->customer)(daysAgo: 3));
    $this->context->bypass(fn () => DB::table('rewards')->where('id', $expired->id)->update(['expires_at' => now()->subMinute()]));
    ($this->reward)(($this->customer)(daysAgo: 3), at: $this->tenants->a2);
    ($this->reward)(($this->customer)(daysAgo: 3, pushRewards: false));

    ($this->run)();
    Carbon::setTestNow(now()->addHour());
    ($this->run)();

    expect(($this->sends)())->toBe([[$waiting->id, 'reward_reminder', 'reward:'.$reward->id]]);
});

it('reminds of a second reward once the first was', function (): void {
    ($this->automation)(AutomationTrigger::RewardReminder, ['days' => 7]);
    $customer = ($this->customer)(daysAgo: 3);
    $first = ($this->reward)($customer, daysAgo: 20);
    $second = ($this->reward)($customer, daysAgo: 10, milestone: 2);

    ($this->run)();
    Carbon::setTestNow(now()->addDays(8));
    ($this->run)();

    expect(array_column(($this->sends)(), 2))->toBe(['reward:'.$first->id, 'reward:'.$second->id]);
});

it('sends at most one message per customer per business a week, a reward reminder first', function (): void {
    ($this->automation)(AutomationTrigger::WinBack);
    ($this->automation)(AutomationTrigger::RewardReminder, ['days' => 7]);
    $customer = ($this->customer)();
    ($this->reward)($customer);

    ($this->run)();
    Carbon::setTestNow(now()->addDays(6)->addHours(23));
    ($this->run)();

    expect(array_column(($this->sends)(), 1))->toBe(['reward_reminder']);

    Carbon::setTestNow(now()->addHours(2));
    ($this->run)();

    expect(array_column(($this->sends)(), 1))->toBe(['reward_reminder', 'win_back']);
});

it('sends only between 08:00 and 22:00 in the business\'s time', function (string $timezone, string $utc, bool $sends): void {
    $this->context->bypass(fn () => DB::table('locations')->where('business_id', $this->tenants->a1->id)->update(['timezone' => $timezone]));
    ($this->automation)(AutomationTrigger::WinBack);
    ($this->customer)();
    Carbon::setTestNow($utc);

    expect(($this->run)()['claimed'])->toBe($sends ? 1 : 0);
})->with([
    'Casablanca 21:59' => ['Africa/Casablanca', '2026-11-04 20:59:00', true],
    'Casablanca 22:00' => ['Africa/Casablanca', '2026-11-04 21:00:00', false],
    'Casablanca 07:59' => ['Africa/Casablanca', '2026-11-05 06:59:00', false],
    'Casablanca 08:00' => ['Africa/Casablanca', '2026-11-05 07:00:00', true],
    'Dubai 21:59' => ['Asia/Dubai', '2026-11-04 17:59:00', true],
    'Dubai 22:00' => ['Asia/Dubai', '2026-11-04 18:00:00', false],
    'Dubai 07:59' => ['Asia/Dubai', '2026-11-05 03:59:00', false],
    'Dubai 08:00' => ['Asia/Dubai', '2026-11-05 04:00:00', true],
]);

it('sends nothing for a business not allowed automations, suspended, archived, or with the automation off', function (string $case): void {
    ($this->automation)(AutomationTrigger::WinBack, ['active' => $case !== 'turned off']);
    ($this->customer)();

    match ($case) {
        'not allowed' => Feature::for($this->tenants->a1)->deactivate(Automations::class),
        'suspended' => DB::table('businesses')->where('id', $this->tenants->a1->id)->update(['status' => BusinessStatus::Suspended->value]),
        'archived' => DB::table('businesses')->where('id', $this->tenants->a1->id)->update(['archived_at' => now()]),
        'turned off' => null,
    };

    expect(($this->run)())->toBe(['businesses' => 0, 'claimed' => 0])
        ->and(($this->sends)())->toBe([]);
})->with(['not allowed', 'suspended', 'archived', 'turned off']);

it('sends nothing without the app\'s push key', function (): void {
    config(['webpush.vapid.public_key' => null]);
    ($this->automation)(AutomationTrigger::WinBack);
    ($this->customer)();

    expect(($this->run)()['claimed'])->toBe(0);
});

it('keeps each business to its own customers and automations', function (): void {
    Feature::for($this->tenants->a2)->activate(Automations::class);
    ($this->automation)(AutomationTrigger::WinBack, business: $this->tenants->a2);
    $a1s = ($this->customer)();
    $a2s = ($this->customer)(at: $this->tenants->a2);

    ($this->run)();

    expect(($this->sends)())->toHaveCount(1)
        ->and(($this->sends)()[0][0])->toBe($a2s->id)
        ->and($this->context->bypass(fn (): int => AutomationSend::query()->value('business_id')))->toBe($this->tenants->a2->id)
        ->and($a1s->id)->not->toBe($a2s->id);
});

it('never reminds of a reward a stamp unlocked without a visit here', function (): void {
    ($this->automation)(AutomationTrigger::RewardReminder, ['days' => 7]);
    $customer = ($this->customer)(daysAgo: 3);
    $enrollment = $this->context->bypass(fn (): CardEnrollment => CardEnrollment::query()->where('user_id', $customer->id)->firstOrFail());
    $bonus = $this->tenants->stamp($enrollment, $this->tenants->a1, ['source' => StampSource::Bonus, 'idempotency_key' => 'bonus:1', 'created_at' => now()->subDays(10)]);
    $this->context->bypass(fn (): Reward => Reward::factory()->for($enrollment, 'enrollment')->create(['stamp_event_id' => $bonus->id, 'unlocked_at' => now()->subDays(10)]));

    expect(($this->run)()['claimed'])->toBe(0);
});

it('keeps organization B to its own customers, and A to its own, for a customer of both', function (): void {
    Feature::for($this->tenants->b1)->activate(Automations::class);
    ($this->automation)(AutomationTrigger::WinBack);
    ($this->automation)(AutomationTrigger::WinBack, business: $this->tenants->b1);
    // Quiet at A1, and a customer of B1 too, where they came in yesterday.
    $both = ($this->customer)();
    $atB = $this->tenants->enroll($both, $this->tenants->cardB);
    $this->context->bypass(fn () => (new Follow)->forceFill(['organization_id' => $this->tenants->orgB->id, 'user_id' => $both->id, 'notify' => true])->save());
    $this->tenants->stamp($atB, $this->tenants->b1, ['created_at' => now()->subDay()]);
    app(SummariseCustomer::class)->handle($this->tenants->b1->id, $this->tenants->orgB->id, $both->id);
    $onlyB = ($this->customer)(at: $this->tenants->b1, card: $this->tenants->cardB);

    ($this->run)();

    // Each from its own: A1 wins back the customer of both (quiet there), B1 only its own (the other came in yesterday).
    expect($this->context->bypass(fn (): array => AutomationSend::query()->get(['business_id', 'user_id'])
        ->map(fn (AutomationSend $send): array => [$send->business_id, $send->user_id])->all()))
        ->toEqualCanonicalizing([[$this->tenants->a1->id, $both->id], [$this->tenants->b1->id, $onlyB->id]]);
});

it('runs hourly, never twice at once', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === 'punchcard:automations');

    expect($event?->expression)->toBe('0 * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->onOneServer)->toBeTrue();
});
