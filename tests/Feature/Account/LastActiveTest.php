<?php

declare(strict_types=1);

use App\Actions\Account\RecordActivity;
use App\Actions\Rewards\RedeemPresence;
use App\Actions\Rewards\RedeemReward;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Actions\Stamps\StampResult;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Events\DailyCapReached;
use App\Events\EnrollmentChanged;
use App\Models\CounterScan;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| When an account was last used (CHW-58)
|--------------------------------------------------------------------------
|
| What the inactivity rule reads: a signed-in request, a stamp that proves
| the customer was there (tap, scan, manual) or a redemption. Noted once a
| day at most, after the commit, never on an anonymised account; it also
| withdraws an inactivity warning. Accounts from before it start from what
| is known.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create(['last_active_at' => '2026-07-01 08:00:00', 'inactivity_warned_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-06-01 08:00:00']);
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->location = $this->tenants->locationOf($this->tenants->a1);
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['cooldown_min' => 0, 'daily_cap' => null])->save());

    $this->add = fn (StampRequest $request): StampResult => $this->context->bypass(
        fn (): StampResult => app(AddStamps::class)->handle($this->enrollment, $request),
    );
    $this->scan = fn (): StampRequest => StampRequest::qr($this->location, $this->staff, (string) Str::uuid());
    $this->lastActive = fn (): ?string => User::query()->findOrFail($this->customer->id)->last_active_at?->toDateTimeString();
    $this->userUpdates = function (Closure $work): int {
        $updates = new stdClass;
        $updates->count = 0;
        DB::listen(function (QueryExecuted $query) use ($updates): void {
            $updates->count += preg_match('/^update\s+"users"/i', $query->sql);
        });
        $work();

        return $updates->count;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('signed-in requests', function (): void {
    it('note the day\'s first one, withdraw the warning and leave the profile\'s updated_at', function (): void {
        $this->actingAs($this->customer)->get(route('privacy.edit'))->assertOk();
        $user = User::query()->findOrFail($this->customer->id);

        expect($user->last_active_at?->toDateTimeString())->toBe('2026-10-20 10:00:00')
            ->and($user->inactivity_warned_at)->toBeNull()
            ->and($user->updated_at?->toDateTimeString())->toBe('2026-06-01 08:00:00');
    });

    it('write nothing more the same day', function (): void {
        $this->actingAs($this->customer)->get(route('privacy.edit'));
        $this->travel(5)->hours();

        expect(($this->userUpdates)(fn () => $this->actingAs($this->customer)->get(route('privacy.edit'))->assertOk()))->toBe(0)
            ->and(($this->lastActive)())->toBe('2026-10-20 10:00:00');
    });

    it('count signing in', function (): void {
        $this->post(route('login.store'), ['email' => $this->customer->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($this->customer);
        expect(($this->lastActive)())->toBe('2026-10-20 10:00:00');
    });

    it('write nothing for a guest', function (): void {
        expect(($this->userUpdates)(fn () => $this->get(route('home'))->assertOk()))->toBe(0);
    });
});

describe('stamps and redemptions', function (): void {
    it('count a stamp that proves the customer was there', function (Closure $request): void {
        ($this->add)($request->call($this));

        expect(($this->lastActive)())->toBe('2026-10-20 10:00:00')
            ->and(User::query()->findOrFail($this->customer->id)->inactivity_warned_at)->toBeNull();
    })->with([
        'a tap' => [fn (): StampRequest => StampRequest::nfc($this->tenants->stamper($this->tenants->a1), 7)],
        'a scan' => [fn (): StampRequest => ($this->scan)()],
        'a manual stamp' => [fn (): StampRequest => StampRequest::manual($this->location, $this->staff, (string) Str::uuid(), 'Card forgotten', 1)],
    ]);

    it('never count one that does not', function (Closure $request): void {
        ($this->add)(($this->scan)());
        User::query()->whereKey($this->customer->id)->update(['last_active_at' => '2026-07-01 08:00:00']);
        $this->travel(1)->days();

        ($this->add)($request->call($this));

        expect(($this->lastActive)())->toBe('2026-07-01 08:00:00');
    })->with([
        'a correction' => [fn (): StampRequest => StampRequest::correction($this->location, $this->staff, (string) Str::uuid(), 'Given twice', -1)],
        'a bonus' => [fn (): StampRequest => StampRequest::system(StampSource::Bonus, $this->location, (string) Str::uuid())],
        'a referral' => [fn (): StampRequest => StampRequest::system(StampSource::Referral, $this->location, (string) Str::uuid())],
    ]);

    it('note a card once a day: its next stamp that day writes nothing', function (): void {
        ($this->add)(($this->scan)());
        $this->travel(2)->hours();

        expect(($this->userUpdates)(fn () => ($this->add)(($this->scan)())))->toBe(0);

        $this->travel(1)->days();

        expect(($this->userUpdates)(fn () => ($this->add)(($this->scan)())))->toBe(1)
            ->and(($this->lastActive)())->toBe('2026-10-21 12:00:00');
    });

    it('note a stamp only once it commits, outside the ledger\'s locks', function (): void {
        $writes = ($this->userUpdates)(function (): void {
            DB::transaction(function (): void {
                ($this->add)(($this->scan)());

                expect(($this->lastActive)())->toBe('2026-07-01 08:00:00');
            });
        });

        expect($writes)->toBe(1)
            ->and(($this->lastActive)())->toBe('2026-10-20 10:00:00');
    });

    it('wait for the response during a web request, with one write for the signed-in customer', function (): void {
        $test = $this;
        $seen = new stdClass;
        Route::middleware('web')->get('/_test/stamp', function () use ($test, $seen): string {
            ($test->add)(($test->scan)());
            $seen->during = ($test->lastActive)();

            return 'stamped';
        });

        expect(($this->userUpdates)(fn () => $this->actingAs($this->customer)->get('/_test/stamp')->assertOk()))->toBe(1)
            ->and($seen->during)->toBe('2026-07-01 08:00:00')
            ->and(($this->lastActive)())->toBe('2026-10-20 10:00:00');
    });

    it('note the customer a staff member scanned, once the staff member\x27s response is sent', function (): void {
        $test = $this;
        Route::middleware('web')->get('/_test/scan', function () use ($test): string {
            ($test->add)(($test->scan)());

            return 'stamped';
        });

        $this->actingAs($this->staff)->get('/_test/scan')->assertOk();

        expect(($this->lastActive)())->toBe('2026-10-20 10:00:00');
    });

    it('count nothing for a stamp rolled back', function (): void {
        try {
            DB::transaction(function (): void {
                ($this->add)(($this->scan)());

                throw new RuntimeException('The tap failed after the stamp.');
            });
        } catch (RuntimeException) {
        }

        expect(($this->lastActive)())->toBe('2026-07-01 08:00:00');
    });

    it('count a redemption', function (): void {
        $reward = $this->tenants->reward($this->enrollment);
        $scan = $this->context->bypass(fn (): CounterScan => CounterScan::factory()->create([
            'user_id' => $this->customer->id, 'staff_id' => $this->staff->id,
            'business_id' => $this->tenants->a1->id, 'location_id' => $this->location->id,
        ]));

        expect(app(RedeemReward::class)->handle($reward, $this->customer, RedeemPresence::scan($scan) ?? throw new LogicException)->redeemedNow)->toBeTrue()
            ->and(($this->lastActive)())->toBe('2026-10-20 10:00:00');

        // Asked again the next day: the first redemption back, nothing new noted.
        $this->travel(1)->days();
        User::query()->whereKey($this->customer->id)->update(['last_active_at' => '2026-07-01 08:00:00']);
        $again = $this->context->bypass(fn (): CounterScan => CounterScan::factory()->create([
            'user_id' => $this->customer->id, 'staff_id' => $this->staff->id,
            'business_id' => $this->tenants->a1->id, 'location_id' => $this->location->id,
        ]));

        expect(app(RedeemReward::class)->handle($reward, $this->customer, RedeemPresence::scan($again) ?? throw new LogicException)->redeemedNow)->toBeFalse()
            ->and(($this->lastActive)())->toBe('2026-07-01 08:00:00');
    });

    it('count nothing for a refused or replayed stamp', function (): void {
        $scan = ($this->scan)();
        ($this->add)($scan);
        $this->travel(1)->days();
        User::query()->whereKey($this->customer->id)->update(['last_active_at' => '2026-07-01 08:00:00']);

        // The same scan retried: the earlier stamp back.
        expect(($this->add)($scan)->replayed)->toBeTrue();

        $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['active' => false])->save());

        expect(fn () => ($this->add)(($this->scan)()))->toThrow(StampRejected::class)
            ->and(($this->lastActive)())->toBe('2026-07-01 08:00:00');
    });

    it('never fail a stamp: a write that fails is reported, and the stamp\'s events still go out', function (): void {
        Event::fake([EnrollmentChanged::class, DailyCapReached::class]);
        $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['daily_cap' => 1])->save());
        DB::listen(function (QueryExecuted $query): void {
            if (preg_match('/^update\s+"users"/i', $query->sql) === 1) {
                throw new RuntimeException('The database went away.');
            }
        });

        expect(($this->add)(($this->scan)())->event->exists)->toBeTrue();
        Event::assertDispatched(EnrollmentChanged::class);
        Event::assertDispatched(DailyCapReached::class);
    });
});

it('never moves back, nor touches an anonymised account', function (): void {
    app(RecordActivity::class)->handle($this->customer, now()->subMonths(4));
    app(RecordActivity::class)->later($this->customer->id, now()->subMonths(4));
    $gone = User::factory()->create(['last_active_at' => null, 'anonymised_at' => now()->subDay()]);

    expect(($this->userUpdates)(fn () => app(RecordActivity::class)->handle($gone, now())))->toBe(0);

    app(RecordActivity::class)->later($gone->id, now());

    expect(($this->lastActive)())->toBe('2026-07-01 08:00:00')
        ->and($gone->fresh()?->last_active_at)->toBeNull();
});

it('starts accounts from before it from what is known, never an anonymised one', function (): void {
    [$quiet, $stamped, $signedIn, $passkey, $redeemed, $consented, $tapped] = User::factory()->count(7)
        ->create(['last_active_at' => null, 'created_at' => '2025-03-01 08:00:00', 'updated_at' => '2025-03-01 08:00:00'])->all();
    $edited = User::factory()->create(['last_active_at' => null, 'created_at' => '2025-03-01 08:00:00', 'updated_at' => '2026-01-01 08:00:00']);
    $gone = User::factory()->create(['last_active_at' => null, 'anonymised_at' => '2026-01-01 08:00:00']);

    $this->context->bypass(function () use ($stamped, $redeemed, $gone): void {
        $this->tenants->enroll($stamped, $this->tenants->cardA)->forceFill(['last_stamp_at' => '2025-08-01 08:00:00'])->save();
        $this->tenants->enroll($stamped, $this->tenants->cardB)->forceFill(['last_stamp_at' => '2025-09-01 08:00:00'])->save();
        $this->tenants->enroll($gone, $this->tenants->cardA)->forceFill(['last_stamp_at' => '2025-09-01 08:00:00'])->save();
        $this->tenants->reward($this->tenants->enroll($redeemed, $this->tenants->cardA))->forceFill([
            'status' => RewardStatus::Redeemed, 'redeemed_at' => '2025-10-01 08:00:00',
            'redeemed_business_id' => $this->tenants->a1->id, 'redeemed_location_id' => $this->location->id,
        ])->save();
    });
    DB::table('sessions')->insert(['id' => 'session-1', 'user_id' => $signedIn->id, 'payload' => '', 'last_activity' => Carbon::parse('2025-11-01 08:00:00')->getTimestamp()]);
    $passkey->passkeys()->create(['name' => 'Phone', 'credential_id' => 'cred-1', 'credential' => []]);
    DB::table('passkeys')->where('user_id', $passkey->id)->update(['last_used_at' => '2025-12-01 08:00:00']);
    DB::table('consent_events')->insert(['user_id' => $consented->id, 'kind' => 'punchcard_news', 'granted' => false, 'source' => 'settings', 'created_at' => '2026-02-01 08:00:00']);
    DB::table('taps')->insert(['user_id' => $tapped->id, 'status' => 'rejected', 'rejection' => 'cooldown', 'created_at' => '2026-03-01 08:00:00', 'updated_at' => '2026-03-01 08:00:00']);

    $migration = require database_path('migrations/2026_10_20_002432_add_last_active_at_to_users_table.php');
    $migration->backfill();

    expect(collect([$quiet, $stamped, $signedIn, $passkey, $redeemed, $edited, $consented, $tapped, $gone])->map(fn (User $user): ?string => $user->fresh()?->last_active_at?->toDateTimeString())->all())->toBe([
        '2025-03-01 08:00:00',
        '2025-09-01 08:00:00',
        '2025-11-01 08:00:00',
        '2025-12-01 08:00:00',
        '2025-10-01 08:00:00',
        '2026-01-01 08:00:00',
        '2026-02-01 08:00:00',
        '2026-03-01 08:00:00',
        null,
    ]);
});
