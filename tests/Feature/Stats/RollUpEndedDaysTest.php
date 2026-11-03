<?php

declare(strict_types=1);

use App\Actions\Stats\RollUpEndedDays;
use App\Models\Business;
use App\Models\BusinessDailyStat;
use App\Models\Location;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Rolling up the days that ended (CHW-45)
|--------------------------------------------------------------------------
|
| Hourly: each business's local days that ended at every open site and are
| not rolled up yet, never more than a week back. --from rolls up history.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(function (): void {
        Location::query()->update(['timezone' => 'Africa/Lagos']);
        Business::query()->update(['created_at' => '2026-01-01 00:00:00']);
    });
    $this->business = $this->tenants->a1;
    $this->run = fn (string $utc, ?string $from = null): int => app(RollUpEndedDays::class)->handle(CarbonImmutable::parse($utc, 'UTC'), $this->business->id, $from);
    // The business's whole-business days rolled up, oldest first.
    $this->days = fn (): array => $this->context->bypass(fn (): array => BusinessDailyStat::query()
        ->where('business_id', $this->business->id)->whereNull('location_id')->orderBy('day')->get()
        ->map(fn (BusinessDailyStat $row): string => $row->day->format('Y-m-d'))->all());
});

it('rolls up the last week\'s ended days, then each day once it has ended', function (): void {
    expect(($this->run)('2026-10-11 11:00:00'))->toBe(7)
        ->and(($this->days)())->toBe(['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10'])
        // 23:30 on the 11th in Lagos: the day has not ended.
        ->and(($this->run)('2026-10-11 22:30:00'))->toBe(0)
        // 00:30 on the 12th: a tap at 23:50 can still be claimed, stamped at 23:50.
        ->and(($this->run)('2026-10-11 23:30:00'))->toBe(0)
        // 00:40: no tap from the 11th is still waiting.
        ->and(($this->run)('2026-10-11 23:40:00'))->toBe(1)
        ->and(($this->days)())->toContain('2026-10-11')
        ->and(($this->run)('2026-10-11 23:45:00'))->toBe(0);
});

it('waits for the day to end at every open site', function (): void {
    // UTC-7: at 00:30 on the 12th in Lagos, it is 16:30 on the 11th there.
    $this->context->bypass(fn () => Location::factory()->create(['business_id' => $this->business->id, 'timezone' => 'America/Los_Angeles']));
    ($this->run)('2026-10-11 11:00:00');

    expect(($this->run)('2026-10-11 23:40:00'))->toBe(0)
        ->and(($this->run)('2026-10-12 07:40:00'))->toBe(1);
});

it('goes back no more than a week, and never before the business began', function (): void {
    ($this->run)('2026-10-01 11:00:00');

    expect(($this->run)('2026-10-20 11:00:00'))->toBe(7)
        ->and(($this->days)()[7])->toBe('2026-10-13');

    $this->context->bypass(fn () => $this->business->forceFill(['created_at' => '2026-10-19 10:00:00'])->save());
    $this->business = $this->tenants->a2;
    $this->context->bypass(fn () => $this->business->forceFill(['created_at' => '2026-10-18 22:30:00'])->save());

    // 23:30 on the 18th in Lagos: its first day is the 18th.
    expect(($this->run)('2026-10-20 11:00:00'))->toBe(2);
});

it('rolls up every ended day from --from, again for days rolled up already', function (): void {
    ($this->run)('2026-10-11 11:00:00');

    expect(($this->run)('2026-10-11 11:00:00', '2026-09-01'))->toBe(40)
        ->and(($this->days)())->toHaveCount(40);
});

it('rolls up history from the command, and refuses a day or a business that is not one', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 11:00:00', 'UTC'));

    $this->artisan('punchcard:stats:rollup', ['--business' => $this->business->id, '--from' => '2026-10-01'])
        ->expectsOutput(trans_choice(':count day rolled up.|:count days rolled up.', 10))->assertSuccessful();
    foreach (['1 Oct', '2026-02-31', '2026-10-1'] as $notADay) {
        $this->artisan('punchcard:stats:rollup', ['--from' => $notADay])
            ->expectsOutput(__('The first day must be a date, as 2026-01-31.'))->assertFailed();
    }
    $this->artisan('punchcard:stats:rollup', ['--business' => 'abc'])
        ->expectsOutput(__('The business must be its id, a number.'))->assertFailed();
});

it('rolls up the other businesses when one fails, and says which', function (): void {
    Log::spy();
    $this->context->bypass(fn () => Location::query()->where('business_id', $this->tenants->a1->id)->update(['timezone' => 'Nowhere/Atlantis']));

    app(RollUpEndedDays::class)->handle(CarbonImmutable::parse('2026-10-11 11:00:00', 'UTC'));

    $this->business = $this->tenants->a2;
    expect(($this->days)())->toHaveCount(7);
    Log::shouldHaveReceived('warning')->with('The rollup skipped a business.', Mockery::on(fn (array $context): bool => $context['business_id'] === $this->tenants->a1->id))->once();
});

it('runs hourly, never twice at once', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === 'punchcard:stats-rollup');

    expect($event?->expression)->toBe('0 * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->onOneServer)->toBeTrue();
});
