<?php

declare(strict_types=1);

use App\Actions\Stampers\MoveStamper;
use App\Actions\Stampers\RecordRekey;
use App\Actions\Stampers\RegisterStamper;
use App\Actions\Stampers\RetireTag;
use App\Actions\Stampers\SetStamperStatus;
use App\Actions\Stampers\StamperRefused;
use App\Actions\Taps\ReceiveTap;
use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\Tap;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Retiring and re-keying tags (CHW-138, docs/runbooks/stamper-keys.md)
|--------------------------------------------------------------------------
|
| A lost or stolen tag is retired, one-way, and its assignment ends; a new
| tag replaces it. A tag suspected copied is re-provisioned off-server, then
| its new key version recorded here: only after every key changed, with its
| stamper paused, from the version the admin saw, and keeping its counter.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->retire = app(RetireTag::class);
    $this->rekey = app(RecordRekey::class);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->uid = $this->context->bypass(fn (): string => $this->stamper->tag()->firstOrFail()->uid);
    $this->tag = fn (): NfcTag => $this->context->bypass(fn (): NfcTag => NfcTag::query()->where('uid', $this->uid)->firstOrFail());
    $this->fresh = fn (Stamper $stamper): Stamper => $this->context->bypass(fn (): Stamper => $stamper->refresh());
    $this->pause = fn () => $this->context->bypass(fn () => $this->stamper->forceFill(['status' => StamperStatus::Disabled])->save());
});

describe('retiring', function (): void {
    it('retires the tag for good and ends its assignment', function (): void {
        $ended = $this->retire->handle($this->uid);

        expect($ended?->id)->toBe($this->stamper->id)
            ->and(($this->tag)()->retired_at)->not->toBeNull()
            ->and(($this->fresh)($this->stamper)->unassigned_at?->equalTo(($this->tag)()->retired_at))->toBeTrue()
            ->and(fn () => app(RegisterStamper::class)->handle($this->uid, $this->tenants->a2))->toThrow(StamperRefused::class, 'retired')
            ->and(fn () => app(MoveStamper::class)->handle($this->uid, $this->tenants->a2))->toThrow(StamperRefused::class, 'retired');
    });

    it('retires a tag nobody holds', function (): void {
        $free = $this->context->bypass(fn (): NfcTag => NfcTag::factory()->create());

        expect($this->retire->handle($free->uid))->toBeNull()
            ->and($this->context->bypass(fn () => $free->refresh()->retired_at))->not->toBeNull();
    });

    it('refuses a tag that is unknown or already retired', function (string $state, string $reason): void {
        $uid = match ($state) {
            'unknown' => '04A1B2C3D4E5F6',
            'retired' => $this->context->bypass(fn (): string => NfcTag::factory()->create(['retired_at' => now()])->uid),
        };

        expect(fn () => $this->retire->handle($uid))->toThrow(StamperRefused::class, $reason);
    })->with([
        'unknown' => ['unknown', 'No tag'],
        'retired' => ['retired', 'already retired'],
    ]);

    it('asks before retiring, and does nothing when told no', function (): void {
        $this->artisan('punchcard:tag:retire', ['uid' => $this->uid])
            ->expectsConfirmation("Retire tag {$this->uid} for good? It can never be assigned again.", 'no')
            ->expectsOutputToContain('Nothing changed')
            ->assertFailed();

        expect(($this->tag)()->retired_at)->toBeNull();

        expect(Artisan::call('punchcard:tag:retire', ['uid' => $this->uid, '--force' => true]))->toBe(0)
            ->and(Artisan::output())
            ->toContain("Retired tag {$this->uid}")
            ->toContain('stamper #'.$this->stamper->id.' at A1, A1 site')
            ->toContain('Register a new tag');
    });
});

describe('re-keying', function (): void {
    it('records the next key version, keeping the counter', function (): void {
        ($this->pause)();
        $this->context->bypass(fn () => NfcTag::query()->where('uid', $this->uid)->update(['last_counter' => 41]));

        $tag = $this->rekey->handle($this->uid, from: 1);

        expect($tag->key_version)->toBe(2)
            ->and(($this->tag)()->key_version)->toBe(2)
            ->and(($this->tag)()->last_counter)->toBe(41);
    });

    it('re-keys a tag nobody holds', function (): void {
        $free = $this->context->bypass(fn (): NfcTag => NfcTag::factory()->create());

        expect($this->rekey->handle($free->uid, from: 1)->key_version)->toBe(2);
    });

    it('refuses a tag that is unknown, retired, still active, at another version or at the last one', function (string $state, string $reason): void {
        $from = 1;
        $uid = $this->uid;
        match ($state) {
            'unknown' => $uid = '04A1B2C3D4E5F6',
            'retired' => $uid = $this->context->bypass(fn (): string => NfcTag::factory()->create(['retired_at' => now()])->uid),
            'active' => null,
            'another version' => [($this->pause)(), $from = 2],
            'the last version' => [($this->pause)(), $this->context->bypass(fn () => NfcTag::query()->where('uid', $this->uid)->update(['key_version' => NfcTag::LAST_KEY_VERSION])), $from = NfcTag::LAST_KEY_VERSION],
        };

        expect(fn () => $this->rekey->handle($uid, from: $from))->toThrow(StamperRefused::class, $reason)
            ->and(($this->tag)()->key_version)->toBe($state === 'the last version' ? NfcTag::LAST_KEY_VERSION : 1);
    })->with([
        'unknown' => ['unknown', 'No tag'],
        'retired' => ['retired', 'retired'],
        'active' => ['active', 'Disable stamper'],
        'another version' => ['another version', 'is at key version 1, not 2'],
        'the last version' => ['the last version', 'last key version'],
    ]);

    it('still re-keys the version before the last one', function (): void {
        ($this->pause)();
        $this->context->bypass(fn () => NfcTag::query()->where('uid', $this->uid)->update(['key_version' => NfcTag::LAST_KEY_VERSION - 1]));

        expect($this->rekey->handle($this->uid, from: NfcTag::LAST_KEY_VERSION - 1)->key_version)->toBe(NfcTag::LAST_KEY_VERSION);
    });

    it('takes the version the tag now has, asks which keys changed, and says what is next', function (): void {
        ($this->pause)();

        $this->artisan('punchcard:tag:rekeyed', ['uid' => $this->uid, 'version' => '2'])
            ->expectsConfirmation("Have keys 2, 3, 4 and then 0 all been changed on tag {$this->uid} to key version 2?", 'no')
            ->expectsOutputToContain('Nothing changed')
            ->assertFailed();

        expect(($this->tag)()->key_version)->toBe(1);

        expect(Artisan::call('punchcard:tag:rekeyed', ['uid' => $this->uid, 'version' => '2', '--force' => true]))->toBe(0)
            ->and(Artisan::output())
            ->toContain("Tag {$this->uid} is now at key version 2; its counter (0) is unchanged.")
            ->toContain("If you disabled stamper #{$this->stamper->id} in step 1, re-enable it with punchcard:stamper:enable {$this->uid}");
    });

    it('refuses the same re-key run twice, as after a dropped session', function (): void {
        ($this->pause)();
        Artisan::call('punchcard:tag:rekeyed', ['uid' => $this->uid, 'version' => '2', '--force' => true]);

        expect(Artisan::call('punchcard:tag:rekeyed', ['uid' => $this->uid, 'version' => '2', '--force' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('is at key version 2, not 1')
            ->and(($this->tag)()->key_version)->toBe(2);
    });

    it('refuses a version that is not a whole number of 2 or more', function (string $version): void {
        expect(Artisan::call('punchcard:tag:rekeyed', ['uid' => $this->uid, 'version' => $version, '--force' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('2 or more')
            ->and(($this->tag)()->key_version)->toBe(1);
    })->with(['1', '0', 'two', '2.5']);
});

describe('disabling and enabling', function (): void {
    it('disables and re-enables the tag\'s stamper', function (): void {
        expect(Artisan::call('punchcard:stamper:disable', ['uid' => $this->uid]))->toBe(0)
            ->and(Artisan::output())->toContain("Stamper #{$this->stamper->id} at A1, A1 site")->toContain('refuses every tap')
            ->and(($this->fresh)($this->stamper)->status)->toBe(StamperStatus::Disabled);

        expect(Artisan::call('punchcard:stamper:enable', ['uid' => $this->uid]))->toBe(0)
            ->and(Artisan::output())->toContain('is enabled')
            ->and(($this->fresh)($this->stamper)->status)->toBe(StamperStatus::Active);
    });

    it('says when the stamper already had that status, so a business\'s own pause is not undone', function (): void {
        ($this->pause)();

        expect(Artisan::call('punchcard:stamper:disable', ['uid' => $this->uid]))->toBe(0)
            ->and(Artisan::output())->toContain('was already disabled, perhaps by the business');

        $this->context->bypass(fn () => $this->stamper->forceFill(['status' => StamperStatus::Active])->save());

        expect(Artisan::call('punchcard:stamper:enable', ['uid' => $this->uid]))->toBe(0)
            ->and(Artisan::output())->toContain('was already enabled');
    });

    it('clears the arming when it disables, so armed stamps never land after it is enabled again', function (): void {
        $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 5, 'armed_until' => now()->addMinute()])->save());

        app(SetStamperStatus::class)->handle($this->uid, StamperStatus::Disabled);
        $change = app(SetStamperStatus::class)->handle($this->uid, StamperStatus::Active);

        expect($change->changed())->toBeTrue()
            ->and(($this->fresh)($this->stamper)->armed_qty)->toBeNull()
            ->and(($this->fresh)($this->stamper)->armed_until)->toBeNull();
    });

    it('leaves a live arming alone when it enables a stamper that was enabled', function (): void {
        $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->addMinute()])->save());

        expect(app(SetStamperStatus::class)->handle($this->uid, StamperStatus::Active)->changed())->toBeFalse()
            ->and(($this->fresh)($this->stamper)->armed_qty)->toBe(3);
    });

    it('refuses a tag that is unknown or has no stamper', function (string $state, string $reason): void {
        $uid = match ($state) {
            'unknown' => '04A1B2C3D4E5F6',
            'free' => $this->context->bypass(fn (): string => NfcTag::factory()->create()->uid),
            'retired' => [$this->retire->handle($this->uid), $this->uid][1],
        };

        expect(Artisan::call('punchcard:stamper:disable', ['uid' => $uid]))->toBe(1)
            ->and(Artisan::output())->toContain($reason);
    })->with([
        'unknown' => ['unknown', 'No tag'],
        'free' => ['free', 'is not assigned.'],
        'retired' => ['retired', 'it is retired'],
    ]);
});

it('knows a tag\'s current stamper among its ended ones', function (): void {
    $moved = app(MoveStamper::class)->handle($this->uid, $this->tenants->a2);

    expect($this->context->bypass(fn (): ?int => NfcTag::query()->with('currentStamper')->where('uid', $this->uid)->firstOrFail()->currentStamper?->id))->toBe($moved->id);
});

describe('the tap path', function (): void {
    beforeEach(function (): void {
        config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
        app()->forgetInstance(KeyDiversifier::class);
        $this->receive = function (int $counter, int $keyVersion): Tap {
            $url = app(FakeTap::class)->build($this->uid, $counter, $keyVersion);

            return app(ReceiveTap::class)->handle($url['e'], $url['c'], null, '203.0.113.7', 'Test phone');
        };
    });

    it('refuses URLs signed with the old key after a re-key, keeps old counters replays, and takes the new key', function (): void {
        expect(($this->receive)(5, 1)->rejection)->toBeNull();

        ($this->pause)();
        $this->rekey->handle($this->uid, from: 1);
        $this->context->bypass(fn () => $this->stamper->forceFill(['status' => StamperStatus::Active])->save());

        expect(($this->receive)(6, 1)->rejection)->toBe(TapRejection::BadMac)
            ->and(($this->receive)(5, 2)->rejection)->toBe(TapRejection::Replay)
            ->and(($this->receive)(7, 2)->rejection)->toBeNull();
    });

    it('refuses every tap on a retired tag, spending its counter', function (): void {
        $this->retire->handle($this->uid);

        expect(($this->receive)(5, 1)->rejection)->toBe(TapRejection::RetiredTag)
            ->and(($this->tag)()->last_counter)->toBe(5);
    });

    it('locks the tag, then its stamper, as the tap path does', function (string $operation): void {
        ($this->pause)();
        DB::enableQueryLog();

        match ($operation) {
            'retire' => $this->retire->handle($this->uid),
            'rekey' => $this->rekey->handle($this->uid, from: 1),
            'disable' => app(SetStamperStatus::class)->handle($this->uid, StamperStatus::Disabled),
        };

        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        $tagLock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "nfc_tags"') && str_contains($sql, 'for no key update'));
        $stamperLock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "stampers"') && str_contains($sql, 'for update'));

        expect($tagLock)->toBeInt()
            ->and($stamperLock)->toBeInt()
            ->and($tagLock)->toBeLessThan($stamperLock);
    })->with(['retire', 'rekey', 'disable'])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Row locks compile on Postgres only');
});
