<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\StamperStatus;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| NFC stampers (ADR 0006, sun-nfc-verification skill)
|--------------------------------------------------------------------------
|
| A stamper is site data: a franchisee sees its own, the org admin all of
| the organization's. Its identity and replay state (uid, key_version,
| last_counter) belong to the platform: registered and re-provisioned by an
| admin, advanced by the tap endpoint, all in bypass(). last_counter never
| goes back, whatever writes it. Status, label, location and arming are
| operational (CHW-22 policies).
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->a1Stamper = $this->tenants->stamper($this->tenants->a1);
    $this->a2Stamper = $this->tenants->stamper($this->tenants->a2);
    $this->b1Stamper = $this->tenants->stamper($this->tenants->b1);
});

describe('reads', function (): void {
    it('shows a franchisee its own stampers, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
        $expected = match ($tenant) {
            'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), [$this->a1Stamper->id]],
            'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), [$this->a2Stamper->id]],
            'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), [$this->a1Stamper->id, $this->a2Stamper->id]],
            'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), [$this->b1Stamper->id]],
            'no tenant' => [null, []],
        };

        expect(Stamper::query()->orderBy('id')->pluck('id')->all())->toBe($expected[1]);
    })->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);
});

describe('identity and replay state', function (): void {
    it('registers a stamper only in bypass()', function (string $tenant): void {
        match ($tenant) {
            'org admin' => $this->context->set($this->tenants->orgA, orgAdmin: true),
            'owner' => $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner),
        };

        (new Stamper)->forceFill([
            'business_id' => $this->tenants->a1->id, 'location_id' => $this->tenants->locationOf($this->tenants->a1)->id, 'uid' => '04AABBCCDDEEFF',
        ])->save();
    })->throws(LogicException::class, 'registered by an admin action')->with(['org admin', 'owner']);

    it('deletes a stamper only in bypass(): a lost one is disabled', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        match ($how) {
            'model' => $this->a1Stamper->delete(),
            'bulk' => Stamper::query()->delete(),
        };
    })->throws(LogicException::class, 'disabled, not deleted')->with(['model', 'bulk']);

    it('lets an owner disable, label, move and arm a stamper', function (): void {
        $a1Second = $this->context->bypass(fn () => $this->tenants->a1->locations()->create(['name' => 'A1 terrace', 'timezone' => 'Africa/Casablanca']));
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

        $this->a1Stamper->forceFill([
            'status' => StamperStatus::Disabled, 'label' => 'Counter', 'location_id' => $a1Second->id, 'armed_qty' => 2, 'armed_until' => now()->addMinute(),
        ])->save();

        expect($this->a1Stamper->refresh()->status)->toBe(StamperStatus::Disabled)
            ->and($this->a1Stamper->location_id)->toBe($a1Second->id);
    });

    it('changes keys and the counter only in bypass()', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        match ($how) {
            'bump the key version' => $this->a1Stamper->forceFill(['key_version' => 2])->save(),
            'advance the counter' => $this->a1Stamper->forceFill(['last_counter' => 99])->save(),
            'bulk' => Stamper::query()->update(['last_counter' => 99]),
        };
    })->throws(LogicException::class, 'in TenantContext::bypass()')->with(['bump the key version', 'advance the counter', 'bulk']);

    it('lets bypass() re-provision a stamper and advance its counter', function (): void {
        $this->context->bypass(fn () => $this->a1Stamper->forceFill(['key_version' => 2, 'last_counter' => 61])->save());

        expect($this->context->bypass(fn () => $this->a1Stamper->refresh()->only(['key_version', 'last_counter'])))
            ->toBe(['key_version' => 2, 'last_counter' => 61]);
    });

    it('never changes a stamper\'s uid, even in bypass()', function (): void {
        $this->context->bypass(fn () => $this->a1Stamper->forceFill(['uid' => '04FFFFFFFFFFFF'])->save());
    })->throws(LogicException::class, 'uid cannot change');

    it('never moves the counter back, whatever writes it', function (string $how): void {
        $this->context->bypass(fn () => $this->a1Stamper->forceFill(['last_counter' => 61])->save());

        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($how) {
            'model in bypass()' => $this->a1Stamper->forceFill(['last_counter' => 60])->save(),
            'raw query' => DB::table('stampers')->where('id', $this->a1Stamper->id)->update(['last_counter' => 0]),
        })))->toThrow(QueryException::class, 'stampers_counter_forward');
    })->with(['model in bypass()', 'raw query']);
});

describe('integrity', function (): void {
    it('keeps a stamper at a location of its own business, even in bypass()', function (): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(
            fn () => $this->a1Stamper->forceFill(['location_id' => $this->tenants->locationOf($this->tenants->a2)->id])->save(),
        )))->toThrow(QueryException::class);
    });

    it('keeps a location with stampers from being deleted', function (): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(
            fn () => $this->tenants->locationOf($this->tenants->a1)->delete(),
        )))->toThrow(QueryException::class);
    });

    it('registers a tag once', function (): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => (new Stamper)->forceFill([
            'business_id' => $this->tenants->b1->id, 'location_id' => $this->tenants->locationOf($this->tenants->b1)->id, 'uid' => $this->a1Stamper->uid,
        ])->save())))->toThrow(QueryException::class);
    });

    it('lets Postgres refuse an impossible stamper', function (array $values, string $constraint): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => (new Stamper)->forceFill([
            'business_id' => $this->tenants->a1->id, 'location_id' => $this->tenants->locationOf($this->tenants->a1)->id, 'uid' => '04A1B2C3D4E5F6', ...$values,
        ])->save())))->toThrow(QueryException::class, $constraint);
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'CHECK constraints are Postgres only')->with([
        'a lowercase uid' => [['uid' => '04a1b2c3d4e5f6'], 'stampers_uid_check'],
        'a short uid' => [['uid' => '04A1B2'], 'stampers_uid_check'],
        'key version 0' => [['key_version' => 0], 'stampers_key_version_check'],
        'a counter past 24 bits' => [['last_counter' => 16_777_216], 'stampers_last_counter_check'],
        'armed for 11 stamps' => [['armed_qty' => 11, 'armed_until' => now()], 'stampers_armed_check'],
        'armed without a deadline' => [['armed_qty' => 2], 'stampers_armed_check'],
    ]);
});
