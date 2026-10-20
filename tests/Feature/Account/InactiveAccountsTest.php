<?php

declare(strict_types=1);

use App\Actions\Account\AnonymiseInactiveAccounts;
use App\Actions\Account\InactiveCustomers;
use App\Actions\Account\RecordActivity;
use App\Actions\Account\WarnInactiveAccounts;
use App\Enums\BusinessRole;
use App\Enums\PlatformRole;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Notifications\InactiveAccountMail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The inactivity rule (CHW-58)
|--------------------------------------------------------------------------
|
| A customer account unused for 24 months goes, the way of a deletion they
| asked for: deleted with no history, else anonymised. Its owner is emailed
| 30 days before, in their language, and always gets those 30 days; using
| the account withdraws the warning. Owners, staff, org admins and platform
| admins are never swept, nor anonymised accounts. Runs with no tenant set,
| as the scheduler does.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20 03:30:00');
    Notification::fake();
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = fn (array $attributes = []): User => User::factory()->create(['last_active_at' => '2024-09-01 08:00:00', ...$attributes]);
    $this->warn = fn (): int => app(WarnInactiveAccounts::class)->handle(now());
    $this->sweep = fn (): int => app(AnonymiseInactiveAccounts::class)->handle(now());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('the warning', function (): void {
    it('goes once, in the customer\'s language, with the day the account goes', function (): void {
        $customer = ($this->customer)(['locale' => 'ar']);

        expect(($this->warn)())->toBe(1)
            ->and($customer->fresh()?->inactivity_warned_at?->toDateTimeString())->toBe('2026-10-20 03:30:00')
            ->and(($this->warn)())->toBe(0);

        Notification::assertSentToTimes($customer, InactiveAccountMail::class, 1);
        Notification::assertSentTo($customer, fn (InactiveAccountMail $mail, array $channels, object $notifiable, ?string $locale): bool => $locale === 'ar'
            && $mail->deleteOn->toDateTimeString() === '2026-11-19 03:30:00');
    });

    it('goes 30 days before the limit, counting from sign-up for an account never used since', function (): void {
        $due = ($this->customer)(['last_active_at' => '2024-11-19 03:29:00']);
        $notYet = ($this->customer)(['last_active_at' => '2024-11-19 03:31:00']);
        $neverUsed = ($this->customer)(['last_active_at' => null, 'created_at' => '2024-11-01 08:00:00']);
        $newOne = ($this->customer)(['last_active_at' => null]);

        expect(($this->warn)())->toBe(2);
        Notification::assertSentTo([$due, $neverUsed], InactiveAccountMail::class);
        Notification::assertNotSentTo([$notYet, $newOne], InactiveAccountMail::class);
    });

    it('is never written for an account a sign-in reached first', function (): void {
        $customer = ($this->customer)();
        $signedIn = new stdClass;
        $signedIn->done = false;
        DB::listen(function (QueryExecuted $query) use ($customer, $signedIn): void {
            if (! $signedIn->done && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"inactivity_warned_at" is null')) {
                $signedIn->done = true;
                app(RecordActivity::class)->handle($customer->fresh() ?? $customer, now());
            }
        });

        expect(($this->warn)())->toBe(0)
            ->and($signedIn->done)->toBeTrue()
            ->and($customer->fresh()?->inactivity_warned_at)->toBeNull();
        Notification::assertNotSentTo($customer, InactiveAccountMail::class);
    });

    it('is taken back when the email can\'t be sent, for the next run', function (): void {
        $customer = ($this->customer)();
        Notification::swap(new ChannelManager($this->app));
        $this->app->bind(MailChannel::class, fn (): object => new class
        {
            public function send(object $notifiable, InactiveAccountMail $mail): never
            {
                throw new RuntimeException('The mail server went away.');
            }
        });

        expect(($this->warn)())->toBe(0)
            ->and($customer->fresh()?->inactivity_warned_at)->toBeNull();
    });

    it('is withdrawn when the account is used, and no sweep follows', function (): void {
        $customer = ($this->customer)();
        ($this->warn)();
        app(RecordActivity::class)->handle($customer->fresh() ?? $customer, now()->addDays(3));
        $this->travel(40)->days();

        expect($customer->fresh()?->inactivity_warned_at)->toBeNull()
            ->and(($this->sweep)())->toBe(0)
            ->and($customer->fresh()?->isAnonymised())->toBeFalse();
    });
});

describe('the sweep', function (): void {
    it('waits 30 days after the warning, even for an account unused longer', function (): void {
        $customer = ($this->customer)(['last_active_at' => '2023-01-01 08:00:00', 'inactivity_warned_at' => '2026-09-20 03:31:00']);
        $neverWarned = ($this->customer)(['last_active_at' => '2023-01-01 08:00:00']);

        expect(($this->sweep)())->toBe(0);

        $this->travel(1)->minutes();

        expect(($this->sweep)())->toBe(1)
            ->and($customer->fresh())->toBeNull()
            ->and($neverWarned->fresh())->not->toBeNull()
            ->and(($this->sweep)())->toBe(0);
    });

    it('never takes an account still under the limit, whatever its warning says', function (): void {
        // Warned under a shorter limit, say, before it was raised.
        $customer = ($this->customer)(['last_active_at' => '2025-01-01 08:00:00', 'inactivity_warned_at' => '2026-09-01 08:00:00']);

        expect(($this->sweep)())->toBe(0)
            ->and($customer->fresh())->not->toBeNull();
    });

    it('anonymises an account with history and keeps the ledger, rewards and businesses\' counts', function (): void {
        $customer = ($this->customer)(['inactivity_warned_at' => '2026-09-01 08:00:00']);
        $enrollment = $this->tenants->enroll($customer, $this->tenants->cardA);
        $this->tenants->stamp($enrollment, $this->tenants->a1);
        $reward = $this->tenants->reward($enrollment);
        $stamps = fn (): int => $this->context->bypass(fn (): int => StampEvent::query()->where('enrollment_id', $enrollment->id)->count());

        expect(($this->sweep)())->toBe(1);

        $gone = $customer->fresh();

        expect($gone?->isAnonymised())->toBeTrue()
            ->and($gone?->name)->toBe('Deleted user')
            ->and($gone?->inactivity_warned_at)->toBeNull()
            ->and($stamps())->toBe(1)
            ->and($this->context->bypass(fn (): bool => Reward::query()->whereKey($reward->id)->exists()))->toBeTrue();
    });

    it('leaves an account that a sign-in reached first, once it holds the row', function (): void {
        $customer = ($this->customer)(['inactivity_warned_at' => '2026-09-01 08:00:00']);
        $signedIn = new stdClass;
        $signedIn->done = false;
        DB::listen(function (QueryExecuted $query) use ($customer, $signedIn): void {
            if (! $signedIn->done && str_contains($query->sql, '"taps"')) {
                $signedIn->done = true;
                app(RecordActivity::class)->handle($customer->fresh() ?? $customer, now());
            }
        });

        expect(($this->sweep)())->toBe(0)
            ->and($signedIn->done)->toBeTrue()
            ->and($customer->fresh()?->isAnonymised())->toBeFalse();
    });

    it('never touches staff, owners, org admins or platform admins, archived ones included, nor an anonymised account', function (): void {
        $warned = ['last_active_at' => '2023-01-01 08:00:00', 'inactivity_warned_at' => '2026-08-01 08:00:00'];
        $staff = $this->tenants->member(($this->customer)($warned), $this->tenants->a2);
        $owner = $this->tenants->member(($this->customer)($warned), $this->tenants->a1, BusinessRole::Owner);
        $orgAdmin = $this->tenants->admin(($this->customer)($warned), $this->tenants->orgB);
        $platformAdmin = ($this->customer)($warned);
        $platformAdmin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
        $anonymised = ($this->customer)(['last_active_at' => '2023-01-01 08:00:00', 'inactivity_warned_at' => null, 'anonymised_at' => '2025-01-01 08:00:00']);
        $this->context->bypass(fn () => $this->tenants->a2->forceFill(['archived_at' => now()])->save());
        User::query()->whereKey([$staff->id, $owner->id, $orgAdmin->id, $platformAdmin->id])->update(['inactivity_warned_at' => null]);

        expect(($this->warn)())->toBe(0);

        User::query()->whereKey([$staff->id, $owner->id, $orgAdmin->id, $platformAdmin->id])->update(['inactivity_warned_at' => '2026-08-01 08:00:00']);

        expect(($this->sweep)())->toBe(0)
            ->and(collect([$staff, $owner, $orgAdmin, $platformAdmin])->every(fn (User $user): bool => $user->fresh()?->isAnonymised() === false))->toBeTrue();
        Notification::assertNotSentTo([$staff, $owner, $orgAdmin, $platformAdmin, $anonymised], InactiveAccountMail::class);
    });

    it('moves on past an account that fails, and reports it', function (): void {
        Exceptions::fake();
        $failing = ($this->customer)(['inactivity_warned_at' => '2026-09-01 08:00:00']);
        $next = ($this->customer)(['inactivity_warned_at' => '2026-09-01 08:00:00']);
        DB::listen(function (QueryExecuted $query) use ($failing): void {
            if (str_contains($query->sql, '"taps"') && in_array($failing->id, $query->bindings, true)) {
                throw new RuntimeException('The database went away.');
            }
        });

        expect(($this->sweep)())->toBe(1)
            ->and($failing->fresh()?->isAnonymised())->toBeFalse()
            ->and($next->fresh())->toBeNull();
        Exceptions::assertReported(RuntimeException::class);
    });
});

it('reads memberships only in bypass(), where they are all visible', function (): void {
    expect(fn () => app(InactiveCustomers::class)->handle(now()))->toThrow(LogicException::class);
});

it('runs every night, warning then sweeping, never twice at once', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === 'punchcard:inactive-accounts');

    expect($event?->expression)->toBe('30 3 * * *')
        ->and($event?->withoutOverlapping)->toBeTrue();
});
