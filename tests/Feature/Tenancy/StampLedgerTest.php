<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The stamp ledger and "stamped here" (ADR 0006, stamp-flow skill)
|--------------------------------------------------------------------------
|
| stamp_events is append-only: nothing updates or deletes it, whatever
| writes, and nothing it references can be hard-deleted (erasure is
| anonymisation, CHW-139). Events are site data: a franchisee sees its own.
| A franchisee sees the customers who stamped there, and the rewards they
| hold or that were redeemed there; the org admin sees the whole program.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->a1Stamper = $this->tenants->stamper($this->tenants->a1);

    // Alice stamped at A1 only, Bob at A2 only, Carol at both; Dave at B1.
    [$this->alice, $this->bob, $this->carol] = array_map(
        fn (): CardEnrollment => $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA),
        range(1, 3),
    );
    $this->dave = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardB);

    $this->aliceAtA1 = $this->tenants->stamp($this->alice, $this->tenants->a1);
    $this->bobAtA2 = $this->tenants->stamp($this->bob, $this->tenants->a2);
    $this->tenants->stamp($this->carol, $this->tenants->a1);
    $this->tenants->stamp($this->carol, $this->tenants->a2);
    $this->tenants->stamp($this->dave, $this->tenants->b1);
});

describe('append-only', function (): void {
    it('never changes or deletes an event through the model, even in bypass()', function (string $how): void {
        $this->context->bypass(fn () => match ($how) {
            'update' => $this->aliceAtA1->forceFill(['qty' => 5])->save(),
            'delete' => $this->aliceAtA1->delete(),
            'bulk update' => StampEvent::query()->update(['qty' => 5]),
            'bulk delete' => StampEvent::query()->delete(),
        });
    })->throws(LogicException::class, 'append-only')->with(['update', 'delete', 'bulk update', 'bulk delete']);

    it('lets the database refuse any change to the ledger, whatever writes it', function (string $how): void {
        expect(fn () => DB::transaction(fn () => match ($how) {
            'raw update' => DB::table('stamp_events')->where('id', $this->aliceAtA1->id)->update(['qty' => 5]),
            'raw delete' => DB::table('stamp_events')->where('id', $this->aliceAtA1->id)->delete(),
            'truncate' => DB::table('stamp_events')->truncate(),
        }))->toThrow(QueryException::class, 'stamp_events_append_only');
    })->with(['raw update', 'raw delete', 'truncate']);
});

describe('who sees what', function (): void {
    it('shows a franchisee its own events, the org admin the organization\'s, never another organization\'s', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);
        $a1Events = StampEvent::query()->pluck('business_id')->unique()->values()->all();

        $this->context->set($this->tenants->orgA, orgAdmin: true);
        $orgAEvents = StampEvent::query()->count();

        $this->context->set($this->tenants->orgB, $this->tenants->b1);

        expect($a1Events)->toBe([$this->tenants->a1->id])
            ->and($orgAEvents)->toBe(4)
            ->and(StampEvent::query()->pluck('enrollment_id')->all())->toBe([$this->dave->id]);
    });

    it('shows a franchisee only the customers who stamped there', function (string $business): void {
        $this->context->set($this->tenants->orgA, $this->tenants->{$business}, businessRole: BusinessRole::Staff);

        expect(CardEnrollment::query()->orderBy('id')->pluck('id')->all())->toBe(match ($business) {
            'a1' => [$this->alice->id, $this->carol->id],
            'a2' => [$this->bob->id, $this->carol->id],
        });
    })->with(['a1', 'a2']);

    it('still shows the org admin every member, from anywhere', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        expect(CardEnrollment::query()->count())->toBe(3);
    });

    it('shows a franchisee the rewards of its customers and those redeemed there', function (): void {
        $aliceReward = $this->tenants->reward($this->alice);
        $bobReward = $this->tenants->reward($this->bob);
        $this->context->bypass(fn () => $bobReward->forceFill([
            'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id,
        ])->save());

        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);
        $atA1 = Reward::query()->orderBy('id')->pluck('id')->all();
        $bobVisibleAtA1 = CardEnrollment::query()->whereKey($this->bob->id)->exists();

        $this->context->set($this->tenants->orgA, $this->tenants->a2, businessRole: BusinessRole::Staff);

        expect($atA1)->toBe([$aliceReward->id, $bobReward->id])
            ->and($bobVisibleAtA1)->toBeFalse()
            ->and(Reward::query()->pluck('id')->all())->toBe([$bobReward->id]);
    });

    it('limits a franchisee\'s bulk updates to the customers who stamped there', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

        expect(CardEnrollment::query()->update(['updated_at' => now()]))->toBe(2);
    });

    it('does not let a franchisee change progress, even of its own customers', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

        $this->carol->forceFill(['current_stamps' => 50])->save();
    })->throws(LogicException::class, 'cache of the stamp ledger');

    it('makes nobody "stamped here" through a bonus, birthday, referral or correction', function (StampSource $source): void {
        $this->tenants->stamp($this->bob, $this->tenants->a1, ['source' => $source, 'reason' => 'HQ fix', 'qty' => $source === StampSource::Correction ? -1 : 1]);
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

        expect(CardEnrollment::query()->whereKey($this->bob->id)->exists())->toBeFalse();
    })->with([StampSource::Bonus, StampSource::Birthday, StampSource::Referral, StampSource::Correction]);

    it('applies "stamped here" to a referrer too', function (): void {
        [$referredByBob, $referredByAlice] = $this->context->bypass(fn (): array => array_map(function (CardEnrollment $referrer): CardEnrollment {
            $enrollment = (new CardEnrollment)->forceFill(['card_id' => $this->tenants->cardA->id, 'user_id' => User::factory()->create()->id, 'referred_by' => $referrer->id]);
            $enrollment->save();

            return $enrollment;
        }, [$this->bob, $this->alice]));
        $this->tenants->stamp($referredByBob, $this->tenants->a1);
        $this->tenants->stamp($referredByAlice, $this->tenants->a1);
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

        // Both stamped at A1; only Alice's referral is visible there, Bob only stamped at A2.
        expect(CardEnrollment::query()->whereHas('referrer')->pluck('id')->all())->toBe([$referredByAlice->id]);
    });

    it('does not let a franchisee redeem a reward it can see', function (): void {
        $aliceReward = $this->tenants->reward($this->alice);
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

        $aliceReward->forceFill(['status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a2->id])->save();
    })->throws(LogicException::class, 'redeem Action');

    it('shows a reward redeemed here without the customer behind it', function (): void {
        $bobReward = $this->tenants->reward($this->bob);
        $this->context->bypass(fn () => $bobReward->forceFill([
            'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id,
        ])->save());
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

        $reward = Reward::query()->with('enrollment')->findOrFail($bobReward->id);

        expect($reward->enrollment)->toBeNull();
    });
});

describe('writes', function (): void {
    it('records stamps only through the stamp Actions, in bypass(): not even the org admin directly', function (string $tenant): void {
        match ($tenant) {
            'franchisee staff' => $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff),
            'an owner who is org admin' => $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner),
            'HQ' => $this->context->set($this->tenants->orgA, orgAdmin: true),
        };

        // Bob only stamped at A2: a direct insert would stamp him without proof and make him "stamped here".
        (new StampEvent)->forceFill([
            'enrollment_id' => $this->bob->id, 'business_id' => $this->tenants->a1->id,
            'location_id' => $this->tenants->locationOf($this->tenants->a1)->id, 'source' => StampSource::Qr, 'qty' => 1,
        ])->save();
    })->throws(LogicException::class, 'stamp Actions')->with(['franchisee staff', 'an owner who is org admin', 'HQ']);

    it('records a stamp only at a business that honours the card, even in bypass()', function (string $how): void {
        $a2Only = $this->context->bypass(function (): LoyaltyCard {
            $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create();
            $card->businesses()->attach($this->tenants->a2);

            return $card;
        });

        match ($how) {
            'an A2-only card at A1' => $this->tenants->stamp($this->tenants->enroll(User::factory()->create(), $a2Only), $this->tenants->a1),
            'another organization\'s card' => $this->tenants->stamp($this->dave, $this->tenants->a1),
        };
    })->throws(LogicException::class, 'honours the card')->with(['an A2-only card at A1', 'another organization\'s card']);

    it('never rewrites the ledger through upserts, even in bypass()', function (string $how): void {
        $this->context->bypass(fn () => match ($how) {
            'upsert' => StampEvent::query()->upsert([['id' => $this->aliceAtA1->id, 'qty' => 9]], ['id'], ['qty']),
            'updateOrInsert' => StampEvent::query()->updateOrInsert(['id' => $this->aliceAtA1->id], ['qty' => 9]),
        });
    })->throws(LogicException::class, 'append-only')->with(['upsert', 'updateOrInsert']);
});

describe('integrity', function (): void {
    it('accepts each tag counter once, even after the tag moves to another stamper', function (): void {
        $this->tenants->stamp($this->alice, $this->tenants->a1, ['stamper_id' => $this->a1Stamper->id, 'nfc_tag_id' => $this->a1Stamper->nfc_tag_id, 'source' => StampSource::Nfc, 'counter' => 61]);
        $moved = $this->context->bypass(function (): Stamper {
            $this->a1Stamper->forceFill(['unassigned_at' => now()])->save();
            $stamper = (new Stamper)->forceFill([
                'business_id' => $this->tenants->a2->id, 'location_id' => $this->tenants->locationOf($this->tenants->a2)->id, 'nfc_tag_id' => $this->a1Stamper->nfc_tag_id,
            ]);
            $stamper->save();

            return $stamper;
        });

        expect(fn () => DB::transaction(fn () => $this->tenants->stamp($this->carol, $this->tenants->a2, [
            'stamper_id' => $moved->id, 'nfc_tag_id' => $moved->nfc_tag_id, 'source' => StampSource::Nfc, 'counter' => 61,
        ])))->toThrow(QueryException::class);
    });

    it('accepts each idempotency key once per business', function (): void {
        $this->tenants->stamp($this->alice, $this->tenants->a1, ['idempotency_key' => 'scan-1']);
        $this->tenants->stamp($this->carol, $this->tenants->a2, ['idempotency_key' => 'scan-1']);

        expect(fn () => DB::transaction(fn () => $this->tenants->stamp($this->alice, $this->tenants->a1, ['idempotency_key' => 'scan-1'])))
            ->toThrow(QueryException::class);
    });

    it('lets Postgres refuse truncating the ledger through a cascade', function (): void {
        expect(fn () => DB::transaction(fn () => DB::statement('truncate table card_enrollments cascade')))
            ->toThrow(QueryException::class, 'stamp_events_append_only');
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'TRUNCATE CASCADE is Postgres only');

    it('lets the database refuse an event inconsistent with its stamper, tag or location, even in bypass()', function (string $how): void {
        $a2Stamper = $this->tenants->stamper($this->tenants->a2);

        expect(fn () => DB::transaction(fn () => $this->tenants->stamp($this->alice, $this->tenants->a1, match ($how) {
            'another business\'s stamper' => ['stamper_id' => $a2Stamper->id, 'nfc_tag_id' => $a2Stamper->nfc_tag_id, 'source' => StampSource::Nfc, 'counter' => 1],
            'a tag that is not the stamper\'s' => ['stamper_id' => $this->a1Stamper->id, 'nfc_tag_id' => $a2Stamper->nfc_tag_id, 'source' => StampSource::Nfc, 'counter' => 1],
            'another business\'s location' => ['location_id' => $this->tenants->locationOf($this->tenants->a2)->id],
        })))->toThrow(QueryException::class);
    })->with(['another business\'s stamper', 'a tag that is not the stamper\'s', 'another business\'s location']);

    it('keeps everything the ledger points at from being hard-deleted', function (string $what): void {
        $staff = User::factory()->create();
        $this->tenants->stamp($this->alice, $this->tenants->a1, ['stamper_id' => $this->a1Stamper->id, 'nfc_tag_id' => $this->a1Stamper->nfc_tag_id, 'source' => StampSource::Nfc, 'counter' => 7, 'staff_id' => $staff->id]);

        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($what) {
            'the customer' => $this->alice->user()->firstOrFail()->delete(),
            'the staff member' => $staff->delete(),
            'the enrollment' => $this->alice->delete(),
            'the stamper' => $this->a1Stamper->delete(),
            'the location' => $this->tenants->locationOf($this->tenants->a1)->delete(),
            'the business' => $this->tenants->a1->delete(),
            'the organization' => $this->tenants->orgA->delete(),
        })))->toThrow(QueryException::class);
    })->with(['the customer', 'the staff member', 'the enrollment', 'the stamper', 'the location', 'the business', 'the organization']);

    it('lets Postgres refuse an impossible event', function (array $values, string $constraint): void {
        expect(fn () => DB::transaction(fn () => $this->tenants->stamp($this->alice, $this->tenants->a1, $values)))
            ->toThrow(QueryException::class, $constraint);
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'CHECK constraints are Postgres only')->with([
        'more than 50 stamps' => [['qty' => 51], 'stamp_events_qty_check'],
        'a negative qty outside a correction' => [['qty' => -1, 'source' => StampSource::Manual, 'reason' => 'x'], 'stamp_events_negative_check'],
        'a manual stamp without a reason' => [['source' => StampSource::Manual], 'stamp_events_reason_check'],
        'a tap without its tag and counter' => [['source' => StampSource::Nfc], 'stamp_events_nfc_check'],
        'a counter on a QR stamp' => [['counter' => 5], 'stamp_events_nfc_check'],
        'an unknown source' => [['source' => 'magic'], 'stamp_events_source_check'],
        'a QR stamp without staff' => [['staff_id' => null], 'stamp_events_staff_check'],
        'a manual stamp without an idempotency key' => [['source' => StampSource::Manual, 'reason' => 'x', 'idempotency_key' => null], 'stamp_events_staff_check'],
        'an empty reason' => [['source' => StampSource::Manual, 'reason' => ''], 'stamp_events_reason_check'],
    ]);

    it('records a correction only as stamps taken back', function (): void {
        $this->tenants->stamp($this->alice, $this->tenants->a1, ['source' => StampSource::Correction, 'qty' => 1, 'reason' => 'Missed stamps']);
    })->throws(LogicException::class, 'takes stamps back');

    it('lets Postgres refuse a correction that adds stamps, whatever writes it', function (): void {
        $staff = User::factory()->create();

        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn (): bool => StampEvent::query()->insert([
            'organization_id' => $this->tenants->orgA->id,
            'business_id' => $this->tenants->a1->id,
            'location_id' => $this->tenants->locationOf($this->tenants->a1)->id,
            'enrollment_id' => $this->alice->id,
            'staff_id' => $staff->id,
            'source' => StampSource::Correction->value,
            'qty' => 1,
            'idempotency_key' => 'raw-correction',
            'reason' => 'Missed stamps',
            'created_at' => now(),
        ]))))->toThrow(QueryException::class, 'stamp_events_correction_check');
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'CHECK constraints are Postgres only');
});
