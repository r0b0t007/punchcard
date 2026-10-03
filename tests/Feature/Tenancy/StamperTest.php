<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\StamperStatus;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| NFC tags and stampers (ADR 0006, sun-nfc-verification skill)
|--------------------------------------------------------------------------
|
| An NFC tag is platform state: its uid, key version and replay counter.
| It is never deleted, its counter and key version only move forward, and
| retiring it (lost, stolen) is one-way, whatever writes the row. A stamper
| is a tag's assignment to a location of a business: site data, so a
| franchisee sees its own and the org admin the organization's. Removing or
| moving an assignment never resets the tag, so an old tap URL can never
| become valid again; an ended assignment never claims the tag back.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->a1Stamper = $this->tenants->stamper($this->tenants->a1);
    $this->a2Stamper = $this->tenants->stamper($this->tenants->a2);
    $this->b1Stamper = $this->tenants->stamper($this->tenants->b1);
    $this->a1Tag = $this->context->bypass(fn (): NfcTag => $this->a1Stamper->tag()->firstOrFail());
});

describe('stampers', function (): void {
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

    it('assigns a tag only in bypass(), as an admin would', function (string $tenant): void {
        match ($tenant) {
            'org admin' => $this->context->set($this->tenants->orgA, orgAdmin: true),
            'owner' => $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner),
        };

        (new Stamper)->forceFill([
            'business_id' => $this->tenants->a1->id, 'location_id' => $this->tenants->locationOf($this->tenants->a1)->id, 'nfc_tag_id' => $this->a1Tag->id,
        ])->save();
    })->throws(LogicException::class, 'assigned by an admin action')->with(['org admin', 'owner']);

    it('removes an assignment only in bypass(): a franchisee disables it', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        match ($how) {
            'model' => $this->a1Stamper->delete(),
            'bulk' => Stamper::query()->delete(),
        };
    })->throws(LogicException::class, 'disabled, not deleted')->with(['model', 'bulk']);

    it('lets an owner disable, label, move and arm their stamper', function (): void {
        $terrace = $this->context->bypass(fn () => $this->tenants->a1->locations()->create(['name' => 'A1 terrace', 'timezone' => 'Africa/Casablanca']));
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

        $this->a1Stamper->forceFill([
            'status' => StamperStatus::Disabled, 'label' => 'Counter', 'location_id' => $terrace->id, 'armed_qty' => 2, 'armed_until' => now()->addMinute(),
        ])->save();

        expect($this->a1Stamper->refresh()->status)->toBe(StamperStatus::Disabled)
            ->and($this->a1Stamper->location_id)->toBe($terrace->id);
    });

    it('does not let a franchisee or another organization change someone else\'s stamper', function (string $tenant): void {
        match ($tenant) {
            'franchisee A1' => $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner),
            'org admin of B' => $this->context->set($this->tenants->orgB, orgAdmin: true),
        };

        $this->a2Stamper->forceFill(['status' => StamperStatus::Disabled])->save();
    })->throws(LogicException::class)->with(['franchisee A1', 'org admin of B']);

    it('limits bulk changes to the tenant\'s own stampers', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

        expect(Stamper::query()->update(['status' => StamperStatus::Disabled->value]))->toBe(1);

        $this->context->bypass(function (): void {
            expect($this->a2Stamper->refresh()->status)->toBe(StamperStatus::Active)
                ->and($this->b1Stamper->refresh()->status)->toBe(StamperStatus::Active);
        });
    });

    it('never moves a stamper to another tag, even in bypass()', function (): void {
        $this->context->bypass(fn () => $this->a1Stamper->forceFill(['nfc_tag_id' => $this->a2Stamper->nfc_tag_id])->save());
    })->throws(LogicException::class, 'tag cannot change');

    it('never lets an old holder take a moved tag back', function (): void {
        $moved = $this->context->bypass(function (): Stamper {
            $this->a1Stamper->forceFill(['unassigned_at' => now()])->save();
            $stamper = (new Stamper)->forceFill([
                'business_id' => $this->tenants->a2->id, 'location_id' => $this->tenants->locationOf($this->tenants->a2)->id, 'nfc_tag_id' => $this->a1Tag->id,
            ]);
            $stamper->save();

            return $stamper;
        });

        // A2 pauses the tag for the day; A1 re-enables its old assignment.
        $this->context->set($this->tenants->orgA, $this->tenants->a2, businessRole: BusinessRole::Owner);
        $moved->forceFill(['status' => StamperStatus::Disabled])->save();
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);
        $this->a1Stamper->forceFill(['status' => StamperStatus::Active])->save();

        // The tag still belongs to A2, which resumes it.
        $this->context->set($this->tenants->orgA, $this->tenants->a2, businessRole: BusinessRole::Owner);
        $moved->forceFill(['status' => StamperStatus::Active])->save();

        expect($this->context->bypass(fn (): array => Stamper::query()->current()->where('nfc_tag_id', $this->a1Tag->id)->pluck('id')->all()))
            ->toBe([$moved->id]);
    });

    it('does not let a business end or resume an assignment itself', function (): void {
        $this->context->bypass(fn () => $this->a1Stamper->forceFill(['unassigned_at' => now()])->save());
        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        $this->a1Stamper->forceFill(['unassigned_at' => null])->save();
    })->throws(LogicException::class, 'admin action');

    it('keeps an ended assignment ended, even in bypass()', function (): void {
        $this->context->bypass(fn () => $this->a1Stamper->forceFill(['unassigned_at' => now()])->save());

        expect(fn () => DB::transaction(fn () => $this->context->bypass(
            fn () => $this->a1Stamper->forceFill(['unassigned_at' => null])->save(),
        )))->toThrow(QueryException::class, 'stampers_assignment_ended');
    });

    it('keeps one current assignment per tag', function (): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => (new Stamper)->forceFill([
            'business_id' => $this->tenants->a2->id, 'location_id' => $this->tenants->locationOf($this->tenants->a2)->id, 'nfc_tag_id' => $this->a1Tag->id,
        ])->save())))->toThrow(QueryException::class);
    });

    it('keeps a stamper at a location of its own business, and a location with stampers', function (string $how): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($how) {
            'move to another business\'s location' => $this->a1Stamper->forceFill(['location_id' => $this->tenants->locationOf($this->tenants->a2)->id])->save(),
            'delete its location' => $this->tenants->locationOf($this->tenants->a1)->delete(),
        })))->toThrow(QueryException::class);
    })->with(['move to another business\'s location', 'delete its location']);
});

describe('tags', function (): void {
    it('keeps a tag\'s counter and keys when its stamper or business goes, so a new assignment cannot replay old taps', function (string $how): void {
        $this->context->bypass(fn () => $this->a1Tag->forceFill(['last_counter' => 61, 'key_version' => 2])->save());

        match ($how) {
            'the stamper is removed' => $this->context->bypass(fn () => $this->a1Stamper->delete()),
            // A tenant action, not bypass(): franchise HQ may remove a franchisee.
            'HQ removes the franchisee' => [$this->context->set($this->tenants->orgA, orgAdmin: true), $this->tenants->a1->delete()],
        };
        $this->context->clear();

        $reassigned = $this->context->bypass(function (): Stamper {
            $a3 = $this->tenants->orgA->businesses()->create(['name' => 'A3', 'slug' => 'a3']);
            $location = $a3->locations()->create(['name' => 'A3 site', 'timezone' => 'Africa/Casablanca']);
            $stamper = (new Stamper)->forceFill(['business_id' => $a3->id, 'location_id' => $location->id, 'nfc_tag_id' => $this->a1Tag->id]);
            $stamper->save();

            return $stamper;
        });

        expect($this->context->bypass(fn () => $reassigned->tag()->firstOrFail()->only(['last_counter', 'key_version'])))
            ->toBe(['last_counter' => 61, 'key_version' => 2]);
    })->with(['the stamper is removed', 'HQ removes the franchisee']);

    it('reads no tag outside bypass(), not even through an old assignment', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        expect(NfcTag::query()->count())->toBe(0)
            ->and($this->a1Stamper->tag)->toBeNull();
    });

    it('never deletes a tag, whatever deletes it', function (string $how): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($how) {
            'model' => $this->a1Tag->delete(),
            'raw query' => DB::table('nfc_tags')->where('id', $this->a1Tag->id)->delete(),
            'truncate' => DB::table('nfc_tags')->truncate(),
        })))->toThrow(QueryException::class, 'nfc_tags_never_deleted');
    })->with(['model', 'raw query', 'truncate']);

    it('only moves a tag\'s counter and key version forward, keeps its uid and its retirement, whatever writes it', function (string $how): void {
        $this->context->bypass(fn () => $this->a1Tag->forceFill(['last_counter' => 61, 'key_version' => 2, 'retired_at' => now()])->save());

        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($how) {
            'counter back' => $this->a1Tag->forceFill(['last_counter' => 60])->save(),
            'key version back' => $this->a1Tag->forceFill(['key_version' => 1])->save(),
            'new uid' => DB::table('nfc_tags')->where('id', $this->a1Tag->id)->update(['uid' => '04FFFFFFFFFFFF']),
            'un-retire' => DB::table('nfc_tags')->where('id', $this->a1Tag->id)->update(['retired_at' => null]),
            'counter back, raw' => DB::table('nfc_tags')->where('id', $this->a1Tag->id)->update(['last_counter' => 0]),
        })))->toThrow(QueryException::class, 'nfc_tags_forward_only');
    })->with(['counter back', 'key version back', 'new uid', 'un-retire', 'counter back, raw']);

    it('writes tags only in bypass(): they are platform state', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        match ($how) {
            'register' => NfcTag::factory()->create(),
            'advance the counter' => $this->a1Tag->forceFill(['last_counter' => 99])->save(),
            'max the counter through a stamper' => $this->a1Stamper->tag()->update(['last_counter' => 16_777_215]),
            'bump every key version' => NfcTag::query()->increment('key_version'),
            'retire every tag' => NfcTag::query()->update(['retired_at' => now()]),
            'raw insert' => NfcTag::query()->insert(['uid' => '04A1B2C3D4E5F6']),
        };
    })->throws(LogicException::class, 'platform state')->with([
        'register', 'advance the counter', 'max the counter through a stamper', 'bump every key version', 'retire every tag', 'raw insert',
    ]);

    it('lets Postgres refuse an impossible tag or stamper', function (string $case, string $constraint): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($case) {
            'a lowercase uid' => NfcTag::factory()->create(['uid' => '04a1b2c3d4e5f6']),
            'a short uid' => NfcTag::factory()->create(['uid' => '04A1B2']),
            'key version 0' => NfcTag::factory()->create(['key_version' => 0]),
            'a counter past 24 bits' => NfcTag::factory()->create(['last_counter' => 16_777_216]),
            'armed for 11 stamps' => $this->a1Stamper->forceFill(['armed_qty' => 11, 'armed_until' => now()])->save(),
            'armed without a deadline' => $this->a1Stamper->forceFill(['armed_qty' => 2])->save(),
            'an unknown status' => $this->a1Stamper->setRawAttributes([...$this->a1Stamper->getAttributes(), 'status' => 'paused'])->save(),
        })))->toThrow(QueryException::class, $constraint);
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'CHECK constraints are Postgres only')->with([
        'a lowercase uid' => ['a lowercase uid', 'nfc_tags_uid_check'],
        'a short uid' => ['a short uid', 'nfc_tags_uid_check'],
        'key version 0' => ['key version 0', 'nfc_tags_key_version_check'],
        'a counter past 24 bits' => ['a counter past 24 bits', 'nfc_tags_last_counter_check'],
        'armed for 11 stamps' => ['armed for 11 stamps', 'stampers_armed_check'],
        'armed without a deadline' => ['armed without a deadline', 'stampers_armed_check'],
        'an unknown status' => ['an unknown status', 'stampers_status_check'],
    ]);
});
