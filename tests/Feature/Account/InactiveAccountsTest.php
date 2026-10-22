<?php

declare(strict_types=1);

use App\Actions\Account\AnonymiseInactiveAccounts;
use App\Actions\Account\InactiveCustomers;
use App\Actions\Account\RecordActivity;
use App\Actions\Account\WarnInactiveAccounts;
use App\Enums\BusinessRole;
use App\Enums\PlatformRole;
use App\Enums\RewardStatus;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Notifications\InactiveAccountMail;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
| 30 days before, in their language, and the account never goes before the
| day that email named; using the account withdraws the warning, and a tap,
| stamp or sign-in as the sweep runs keeps it. Owners, staff, org admins and
| platform admins are never swept, nor anonymised accounts. Runs with no
| tenant set, as the scheduler does.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20 03:30:00');
    Notification::fake();
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = fn (array $attributes = []): User => User::factory()->create(['last_active_at' => '2024-09-01 08:00:00', ...$attributes]);
    $this->due = ['inactivity_warned_at' => '2026-09-01 08:00:00', 'inactivity_deletes_at' => '2026-10-01 08:00:00'];
    $this->warn = fn (): int => app(WarnInactiveAccounts::class)->handle(now());
    $this->sweep = fn (): int => app(AnonymiseInactiveAccounts::class)->handle(now());
    $this->failingMail = function (): void {
        Notification::swap(new ChannelManager($this->app));
        $this->app->bind(MailChannel::class, fn (): object => new class
        {
            public function send(object $notifiable, InactiveAccountMail $mail): never
            {
                throw new RuntimeException('550 <salma@example.com>: recipient rejected');
            }
        });
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('the warning', function (): void {
    it('goes once, in the customer\'s language, and records the day it promised', function (): void {
        $customer = ($this->customer)(['locale' => 'ar']);

        expect(($this->warn)())->toBe(1)
            ->and($customer->fresh()?->inactivity_warned_at?->toDateTimeString())->toBe('2026-10-20 03:30:00')
            ->and($customer->fresh()?->inactivity_deletes_at?->toDateTimeString())->toBe('2026-11-19 03:30:00')
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

    it('is written only after its email went, and never for an account a sign-in reached meanwhile', function (): void {
        $customer = ($this->customer)();
        $this->app['events']->listen(NotificationSent::class, function () use ($customer): void {
            expect($customer->fresh()?->inactivity_warned_at)->toBeNull();
            app(RecordActivity::class)->handle($customer->fresh() ?? $customer, now());
        });
        Notification::swap(new ChannelManager($this->app));
        $this->app->bind(MailChannel::class, fn (): object => new class
        {
            public function send(object $notifiable, InactiveAccountMail $mail): void {}
        });

        expect(($this->warn)())->toBe(0)
            ->and($customer->fresh()?->inactivity_warned_at)->toBeNull()
            ->and($customer->fresh()?->last_active_at?->toDateTimeString())->toBe('2026-10-20 03:30:00');
    });

    it('is not written when the email can\'t be sent, and logs the account by id only', function (): void {
        Log::spy();
        $customer = ($this->customer)();
        ($this->failingMail)();

        expect(($this->warn)())->toBe(0)
            ->and($customer->fresh()?->inactivity_warned_at)->toBeNull();
        Log::shouldHaveReceived('warning')->once()->with('The inactivity warning could not be sent.', ['user_id' => $customer->id, 'error' => RuntimeException::class]);
    });

    it('sends at most warnings_per_run a night, the rest on the nights after', function (): void {
        config(['punchcard.privacy.warnings_per_run' => 1]);
        $first = ($this->customer)();
        $second = ($this->customer)();

        expect(($this->warn)())->toBe(1);
        Notification::assertSentTo($first, InactiveAccountMail::class);
        Notification::assertNotSentTo($second, InactiveAccountMail::class);

        expect(($this->warn)())->toBe(1);
        Notification::assertSentTo($second, InactiveAccountMail::class);
    });

    it('is withdrawn when the account is used, and no sweep follows', function (): void {
        $customer = ($this->customer)();
        ($this->warn)();
        app(RecordActivity::class)->handle($customer->fresh() ?? $customer, now()->addDays(3));
        $this->travel(40)->days();

        expect($customer->fresh()?->inactivity_warned_at)->toBeNull()
            ->and($customer->fresh()?->inactivity_deletes_at)->toBeNull()
            ->and(($this->sweep)())->toBe(0)
            ->and($customer->fresh()?->isAnonymised())->toBeFalse();
    });

    it('is withdrawn when the limits no longer call for it, so a later one names the right day', function (): void {
        // Warned under a 12-month limit, since raised to 24.
        $customer = ($this->customer)(['last_active_at' => '2025-06-01 08:00:00', ...$this->due]);

        expect(($this->warn)())->toBe(0)
            ->and($customer->fresh()?->inactivity_warned_at)->toBeNull()
            ->and($customer->fresh()?->inactivity_deletes_at)->toBeNull()
            ->and(($this->sweep)())->toBe(0);
    });
});

describe('the sweep', function (): void {
    it('never comes before the day the email named, even with a shorter notice since', function (): void {
        $customer = ($this->customer)(['last_active_at' => '2023-01-01 08:00:00']);
        $neverWarned = ($this->customer)(['last_active_at' => '2023-01-01 08:00:00']);
        config(['punchcard.privacy.warnings_per_run' => 1]);
        ($this->warn)();
        config(['punchcard.privacy.warning_days' => 14]);
        $this->travel(30)->days();
        $this->travel(-1)->minutes();

        expect(($this->sweep)())->toBe(0);

        $this->travel(1)->minutes();

        expect(($this->sweep)())->toBe(1)
            ->and($customer->fresh())->toBeNull()
            ->and($neverWarned->fresh())->not->toBeNull()
            ->and(($this->sweep)())->toBe(0);
    });

    it('never takes an account still under the limit, whatever its warning says', function (): void {
        $customer = ($this->customer)(['last_active_at' => '2025-01-01 08:00:00', ...$this->due]);

        expect(($this->sweep)())->toBe(0)
            ->and($customer->fresh())->not->toBeNull();
    });

    it('anonymises an account with history and keeps the ledger, rewards and businesses\' counts', function (): void {
        $customer = ($this->customer)($this->due);
        $enrollment = $this->tenants->enroll($customer, $this->tenants->cardA);
        $this->tenants->stamp($enrollment, $this->tenants->a1);
        $reward = $this->tenants->reward($enrollment);
        $stamps = fn (): int => $this->context->bypass(fn (): int => StampEvent::query()->where('enrollment_id', $enrollment->id)->count());

        expect(($this->sweep)())->toBe(1);

        $gone = $customer->fresh();

        expect($gone?->isAnonymised())->toBeTrue()
            ->and($gone?->name)->toBe('Deleted user')
            ->and($gone?->inactivity_warned_at)->toBeNull()
            ->and($gone?->inactivity_deletes_at)->toBeNull()
            ->and($stamps())->toBe(1)
            ->and($this->context->bypass(fn (): bool => Reward::query()->whereKey($reward->id)->exists()))->toBeTrue();
    });

    it('leaves an account used as it runs, before last_active_at shows it', function (Closure $use): void {
        $customer = ($this->customer)($this->due);
        $enrollment = $this->tenants->enroll($customer, $this->tenants->cardA);
        $used = new stdClass;
        $used->done = false;
        DB::listen(function (QueryExecuted $query) use ($use, $customer, $enrollment, $used): void {
            if (! $used->done && str_contains($query->sql, '"taps"')) {
                $used->done = true;
                $use->call($this, $customer, $enrollment);
            }
        });

        expect(($this->sweep)())->toBe(0)
            ->and($used->done)->toBeTrue()
            ->and($customer->fresh()?->isAnonymised())->toBeFalse()
            ->and($customer->fresh()?->last_active_at?->toDateTimeString())->toBe('2024-09-01 08:00:00');
    })->with([
        'a stamp' => [function (User $customer, CardEnrollment $enrollment): void {
            $this->context->bypass(fn () => $enrollment->forceFill(['last_stamp_at' => now()])->save());
        }],
        'a redemption' => [function (User $customer, CardEnrollment $enrollment): void {
            $this->tenants->reward($enrollment)->forceFill([
                'status' => RewardStatus::Redeemed, 'redeemed_at' => now(),
                'redeemed_business_id' => $this->tenants->a1->id, 'redeemed_location_id' => $this->tenants->locationOf($this->tenants->a1)->id,
            ])->save();
        }],
        'a tap' => [function (User $customer): void {
            DB::table('taps')->insert(['user_id' => $customer->id, 'status' => 'rejected', 'rejection' => 'cooldown', 'created_at' => now(), 'updated_at' => now()]);
        }],
        'a signed-in request' => [function (User $customer): void {
            DB::table('sessions')->insert(['id' => 'session-1', 'user_id' => $customer->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        }],
    ]);

    it('never touches staff, owners, org admins or platform admins, archived ones included, nor an anonymised account', function (): void {
        $old = ['last_active_at' => '2023-01-01 08:00:00'];
        $staff = $this->tenants->member(($this->customer)($old), $this->tenants->a2);
        $owner = $this->tenants->member(($this->customer)($old), $this->tenants->a1, BusinessRole::Owner);
        $orgAdmin = $this->tenants->admin(($this->customer)($old), $this->tenants->orgB);
        $platformAdmin = ($this->customer)($old);
        $platformAdmin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
        $anonymised = ($this->customer)([...$old, 'anonymised_at' => '2025-01-01 08:00:00']);
        $this->context->bypass(function (): void {
            $this->tenants->a2->forceFill(['archived_at' => now()])->save();
            $this->tenants->orgB->forceFill(['archived_at' => now()])->save();
        });
        $roles = [$staff->id, $owner->id, $orgAdmin->id, $platformAdmin->id];

        expect(($this->warn)())->toBe(0);

        User::query()->whereKey($roles)->update($this->due);

        expect(($this->sweep)())->toBe(0)
            ->and(collect([$staff, $owner, $orgAdmin, $platformAdmin])->every(fn (User $user): bool => $user->fresh()?->isAnonymised() === false))->toBeTrue();
        Notification::assertNotSentTo([$staff, $owner, $orgAdmin, $platformAdmin, $anonymised], InactiveAccountMail::class);
    });

    it('moves on past an account that fails, and logs it by id only', function (): void {
        Log::spy();
        $failing = ($this->customer)($this->due);
        $next = ($this->customer)($this->due);
        DB::listen(function (QueryExecuted $query) use ($failing): void {
            if (str_contains($query->sql, '"taps"') && in_array($failing->id, $query->bindings, true)) {
                throw new RuntimeException('The database went away.');
            }
        });

        expect(($this->sweep)())->toBe(1)
            ->and($failing->fresh()?->isAnonymised())->toBeFalse()
            ->and($next->fresh())->toBeNull();
        Log::shouldHaveReceived('warning')->once()->with('The inactivity sweep skipped an account.', ['user_id' => $failing->id, 'error' => RuntimeException::class]);
    });
});

it('says the day first, in the customer\'s language, with a button to sign in (design B)', function (): void {
    $customer = ($this->customer)(['name' => 'Salma [x](https://evil.test) Tazi', 'locale' => 'ar']);
    app()->setLocale('ar');
    $mail = (new InactiveAccountMail(CarbonImmutable::parse('2026-11-19 03:30:00')))->toMail($customer);
    app()->setLocale('en');

    expect($mail->subject)->toBe('سيُحذف حسابك على punchcard في 19 نوفمبر 2026')
        ->and($mail->introLines[0])->toBe('**سيُحذف حسابك على punchcard في 19 نوفمبر 2026.**')
        ->and($mail->actionText)->toBe('احتفظ بحسابي')
        ->and($mail->actionUrl)->toBe(route('login'))
        ->and((string) (new InactiveAccountMail(CarbonImmutable::parse('2026-11-19')))->toMail(User::factory()->make(['name' => '[Salma](https://evil.test)']))->render())
        ->not->toContain('href="https://evil.test"');
});

it('reads memberships only in bypass(), where they are all visible', function (): void {
    expect(fn () => app(InactiveCustomers::class)->handle(now()))->toThrow(LogicException::class);
});

it('runs every night, the warning and the sweep apart, never twice at once', function (): void {
    $events = collect(app(Schedule::class)->events())->keyBy(fn (ScheduledEvent $event): string => (string) $event->description);

    expect($events->get('punchcard:inactive-accounts-warn')?->expression)->toBe('30 3 * * *')
        ->and($events->get('punchcard:inactive-accounts-sweep')?->expression)->toBe('45 3 * * *')
        ->and($events->get('punchcard:inactive-accounts-warn')?->withoutOverlapping)->toBeTrue()
        ->and($events->get('punchcard:inactive-accounts-sweep')?->withoutOverlapping)->toBeTrue();
});
