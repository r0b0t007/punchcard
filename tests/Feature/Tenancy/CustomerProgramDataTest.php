<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\CardMode;
use App\Enums\RewardStatus;
use App\Enums\RewardType;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Customers' cards and rewards (ADR 0006)
|--------------------------------------------------------------------------
|
| Enrollments and rewards are program data of the organization, and the
| customer data inside it. The org admin works with every member of the
| program, wherever they work (HQ, an independent café's owner). A
| franchisee only sees customers who stamped there (StampLedgerTest covers
| that rule); nobody here has stamped, so a franchisee sees none, and it
| never creates customer data itself: the stamp Action does, in bypass().
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create();
    $this->enrollmentA = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->enrollmentB = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->rewardA = $this->tenants->reward($this->enrollmentA);
});

describe('reads', function (): void {
    it('shows the org admin every member of their program, never another organization\'s', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect(CardEnrollment::query()->pluck('id')->all())->toBe([$this->enrollmentA->id])
            ->and(Reward::query()->pluck('id')->all())->toBe([$this->rewardA->id]);

        $this->context->set($this->tenants->orgB, orgAdmin: true);

        expect(CardEnrollment::query()->pluck('id')->all())->toBe([$this->enrollmentB->id])
            ->and(Reward::query()->count())->toBe(0);
    });

    it('shows a franchisee no customers who have not stamped there', function (string $role): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::from($role));

        expect(CardEnrollment::query()->count())->toBe(0)
            ->and(Reward::query()->count())->toBe(0);
    })->with(['owner', 'staff']);

    it('shows the org admin the whole program from inside a business too (an independent café\'s owner, HQ at its own site)', function (): void {
        $this->context->set($this->tenants->orgB, $this->tenants->b1, orgAdmin: true, businessRole: BusinessRole::Owner);

        expect(CardEnrollment::query()->pluck('id')->all())->toBe([$this->enrollmentB->id]);

        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        expect(CardEnrollment::query()->pluck('id')->all())->toBe([$this->enrollmentA->id])
            ->and(Reward::query()->pluck('id')->all())->toBe([$this->rewardA->id]);
    });

    it('shows nothing without a tenant', function (): void {
        expect(CardEnrollment::query()->count())->toBe(0)
            ->and(Reward::query()->count())->toBe(0);
    });
});

describe('writes', function (): void {
    it('lets an independent café\'s owner enroll a customer and record progress from inside the business', function (): void {
        $this->context->set($this->tenants->orgB, $this->tenants->b1, orgAdmin: true, businessRole: BusinessRole::Owner);

        $enrollment = CardEnrollment::query()->create(['card_id' => $this->tenants->cardB->id, 'user_id' => User::factory()->create()->id]);
        $enrollment->forceFill(['current_stamps' => 1, 'lifetime_stamps' => 1, 'last_stamp_at' => now()])->save();

        expect($enrollment->refresh()->organization_id)->toBe($this->tenants->orgB->id)
            ->and($enrollment->current_stamps)->toBe(1);
    });

    it('does not let a franchisee create customer data itself', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

        match ($how) {
            'enrollment' => CardEnrollment::query()->create(['card_id' => $this->tenants->cardA->id, 'user_id' => User::factory()->create()->id]),
            'reward' => Reward::query()->create(['enrollment_id' => $this->enrollmentA->id, 'mode' => CardMode::Cyclic, 'milestone' => 2, 'reward_type' => RewardType::Item, 'reward_text' => 'x', 'unlocked_at' => now()]),
        };
    })->throws(LogicException::class, 'is created by the stamp Action in TenantContext::bypass()')->with(['enrollment', 'reward']);

    it('refuses a franchisee an enrollment or reward on a card it does not honour', function (string $how): void {
        $a2Only = $this->context->bypass(function (): LoyaltyCard {
            $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create();
            $card->businesses()->attach($this->tenants->a2);

            return $card;
        });
        $a2Member = $this->tenants->enroll(User::factory()->create(), $a2Only);
        // HQ working at its own site A1: org admin, but A1 does not honour that card.
        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        match ($how) {
            'enrollment' => CardEnrollment::query()->create(['card_id' => $a2Only->id, 'user_id' => User::factory()->create()->id]),
            'reward' => Reward::query()->create(['enrollment_id' => $a2Member->id, 'mode' => CardMode::Cyclic, 'milestone' => 1, 'reward_type' => RewardType::Item, 'reward_text' => 'x', 'unlocked_at' => now()]),
        };
    })->throws(LogicException::class, 'is on a card this business does not honour')->with(['enrollment', 'reward']);

    it('refuses a reward created already redeemed or credited to a business', function (array $values): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        (new Reward)->forceFill([
            'enrollment_id' => $this->enrollmentA->id, 'mode' => CardMode::Cyclic, 'milestone' => 9, 'reward_type' => RewardType::Item, 'reward_text' => 'x', 'unlocked_at' => now(),
            ...$values,
        ])->save();
    })->throws(LogicException::class, 'A reward is unlocked available')->with([
        'redeemed' => [['status' => RewardStatus::Redeemed, 'redeemed_at' => now()]],
        'credited to a sibling' => [['redeemed_business_id' => 2]],
        'expired' => [['status' => RewardStatus::Expired]],
    ]);

    it('refuses an enrollment or a reward in another organization', function (string $how): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        match ($how) {
            'enrollment on B\'s card' => CardEnrollment::query()->create(['card_id' => $this->tenants->cardB->id, 'user_id' => User::factory()->create()->id]),
            'reward on B\'s enrollment' => Reward::query()->create(['enrollment_id' => $this->enrollmentB->id, 'mode' => CardMode::Cyclic, 'milestone' => 1, 'reward_type' => RewardType::Item, 'reward_text' => 'x', 'unlocked_at' => now()]),
        };
    })->throws(LogicException::class, 'for another organization')->with(['enrollment on B\'s card', 'reward on B\'s enrollment']);

    it('lets the database refuse an explicit organization other than the card\'s', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect(fn () => DB::transaction(fn () => CardEnrollment::query()->create([
            'organization_id' => $this->tenants->orgA->id, 'card_id' => $this->tenants->cardB->id, 'user_id' => User::factory()->create()->id,
        ])))->toThrow(QueryException::class);
    });

    it('needs a tenant or bypass() to enroll', function (): void {
        CardEnrollment::query()->create(['card_id' => $this->tenants->cardA->id, 'user_id' => User::factory()->create()->id]);
    })->throws(LogicException::class);

    it('does not let a request mass-assign progress or a redemption', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $this->enrollmentA->fill(['current_stamps' => 99, 'completed_count' => 9, 'referred_by' => $this->enrollmentB->id, 'referral_code' => 'VANITY']);
        $this->rewardA->fill(['status' => 'redeemed', 'redeemed_business_id' => $this->tenants->a1->id]);

        expect($this->enrollmentA->isDirty())->toBeFalse()
            ->and($this->rewardA->isDirty())->toBeFalse();
    });

    it('lets the org admin update progress and redeem a reward', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $this->enrollmentA->forceFill(['current_stamps' => 3, 'lifetime_stamps' => 13, 'last_stamp_at' => now()])->save();
        $this->rewardA->forceFill(['status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a2->id])->save();

        expect($this->enrollmentA->refresh()->current_stamps)->toBe(3)
            ->and($this->rewardA->refresh()->status)->toBe(RewardStatus::Redeemed);
    });

    it('keeps a franchisee from changing customer data it cannot see', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

        match ($how) {
            'save an enrollment' => $this->enrollmentA->forceFill(['current_stamps' => 5])->save(),
            'increment an enrollment' => $this->enrollmentA->increment('current_stamps'),
            'redeem a reward' => $this->rewardA->forceFill(['status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id])->save(),
        };
    })->throws(LogicException::class)->with(['save an enrollment', 'increment an enrollment', 'redeem a reward']);

    it('limits a business\'s bulk updates to the customer data it can see (none here)', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

        expect(CardEnrollment::query()->update(['current_stamps' => 9]))->toBe(0)
            ->and(Reward::query()->update(['expires_at' => now()]))->toBe(0);
    });

    it('does not let another organization change A\'s customer data', function (string $how): void {
        $this->context->set($this->tenants->orgB, orgAdmin: true);

        match ($how) {
            'enrollment' => $this->enrollmentA->forceFill(['current_stamps' => 5])->save(),
            'reward' => $this->rewardA->forceFill(['expires_at' => now()])->save(),
        };
    })->throws(LogicException::class)->with(['enrollment', 'reward']);

    it('never moves an enrollment or changes what a reward gave, even in bypass()', function (string $how): void {
        $this->context->bypass(fn () => match ($how) {
            'enrollment to another customer' => $this->enrollmentA->update(['user_id' => User::factory()->create()->id]),
            'enrollment to another card' => $this->enrollmentA->update(['card_id' => $this->tenants->cardB->id]),
            'reward to another enrollment' => $this->rewardA->update(['enrollment_id' => $this->enrollmentB->id]),
            'reward milestone' => $this->rewardA->update(['milestone' => 2]),
            'reward mode' => $this->rewardA->update(['mode' => CardMode::Progressive]),
            'reward text' => $this->rewardA->update(['reward_text' => 'Something cheaper']),
            'reward type' => $this->rewardA->update(['reward_type' => RewardType::Percent]),
            'reward value' => $this->rewardA->update(['reward_value' => 1]),
            'unlock time' => $this->rewardA->update(['unlocked_at' => now()->subYear()]),
            'referrer' => $this->enrollmentA->forceFill(['referred_by' => $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA)->id])->save(),
            'bulk' => CardEnrollment::query()->update(['user_id' => $this->customer->id]),
            'upsert' => Reward::query()->upsert(
                [['enrollment_id' => $this->enrollmentA->id, 'mode' => 'cyclic', 'milestone' => 1, 'reward_type' => 'item', 'reward_text' => 'x', 'unlocked_at' => now(), 'organization_id' => $this->tenants->orgA->id]],
                ['enrollment_id', 'mode', 'milestone'],
                ['reward_text'],
            ),
            'updateOrInsert' => CardEnrollment::query()->updateOrInsert(['id' => $this->enrollmentA->id], ['user_id' => User::factory()->create()->id]),
            'updateFrom' => Reward::query()->updateFrom(['reward_text' => 'x']),
        });
    })->throws(LogicException::class)->with([
        'enrollment to another customer', 'enrollment to another card', 'reward to another enrollment',
        'reward milestone', 'reward mode', 'reward text', 'reward type', 'reward value', 'unlock time', 'referrer',
        'bulk', 'upsert', 'updateOrInsert', 'updateFrom',
    ]);

    it('lets a bypass() upsert name its own conflict columns', function (): void {
        $this->context->bypass(fn (): int => CardEnrollment::query()->upsert(
            [['organization_id' => $this->tenants->orgA->id, 'card_id' => $this->tenants->cardA->id, 'user_id' => $this->customer->id, 'current_stamps' => 7]],
            ['card_id', 'user_id'],
            ['card_id', 'user_id', 'current_stamps'],
        ));

        expect($this->context->bypass(fn (): int => $this->enrollmentA->refresh()->current_stamps))->toBe(7);
    });

    it('deletes customer data only in bypass()', function (string $how): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        match ($how) {
            'an enrollment' => $this->enrollmentA->delete(),
            'a reward' => $this->rewardA->delete(),
            'in bulk' => CardEnrollment::query()->delete(),
        };
    })->throws(LogicException::class, 'deleted only in TenantContext::bypass()')->with(['an enrollment', 'a reward', 'in bulk']);

    it('erases a member with their rewards in bypass()', function (): void {
        $this->context->bypass(fn () => $this->enrollmentA->delete());

        $this->context->bypass(function (): void {
            expect(CardEnrollment::query()->whereKey($this->enrollmentA->id)->exists())->toBeFalse()
                ->and(Reward::query()->whereKey($this->rewardA->id)->exists())->toBeFalse();
        });
    });
});

describe('integrity', function (): void {
    it('enrolls a customer once per card', function (): void {
        expect(fn () => DB::transaction(fn () => $this->tenants->enroll($this->customer, $this->tenants->cardA)))
            ->toThrow(QueryException::class);
    });

    it('creates one reward per milestone of a mode', function (): void {
        expect(fn () => DB::transaction(fn () => $this->tenants->reward($this->enrollmentA)))
            ->toThrow(QueryException::class);

        // A card switched from cyclic to progressive can still reach the same number as a tier.
        $tier = $this->context->bypass(fn (): Reward => Reward::factory()->for($this->enrollmentA, 'enrollment')->create(['mode' => CardMode::Progressive]));

        expect($tier->milestone)->toBe(1);
    });

    it('lets the database refuse a redemption outside the reward\'s organization or business, even in bypass()', function (string $how): void {
        $b1Location = $this->tenants->locationOf($this->tenants->b1);
        $a2Location = $this->tenants->locationOf($this->tenants->a2);

        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($how) {
            'at another organization\'s business' => $this->rewardA->forceFill(['redeemed_business_id' => $this->tenants->b1->id, 'redeemed_location_id' => $b1Location->id])->save(),
            'at a location of another business' => $this->rewardA->forceFill(['redeemed_business_id' => $this->tenants->a1->id, 'redeemed_location_id' => $a2Location->id])->save(),
        })))->toThrow(QueryException::class);
    })->with(['at another organization\'s business', 'at a location of another business']);

    it('lets the database refuse a referrer on another card, even in bypass()', function (): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(
            fn () => (new CardEnrollment)->forceFill(['card_id' => $this->tenants->cardA->id, 'user_id' => User::factory()->create()->id, 'referred_by' => $this->enrollmentB->id])->save(),
        )))->toThrow(QueryException::class);
    });

    it('keeps a referral when the referrer leaves', function (): void {
        $friend = $this->context->bypass(function (): CardEnrollment {
            $friend = (new CardEnrollment)->forceFill(['card_id' => $this->tenants->cardA->id, 'user_id' => User::factory()->create()->id, 'referred_by' => $this->enrollmentA->id]);
            $friend->save();

            return $friend;
        });

        $this->context->bypass(function () use ($friend): void {
            $this->enrollmentA->delete();

            expect($friend->refresh()->referred_by)->toBeNull();
        });
    });

    it('keeps a redeemed or expired reward final, whatever writes it', function (string $how): void {
        $this->context->bypass(fn () => $this->rewardA->forceFill([
            'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id,
        ])->save());
        $expired = $this->context->bypass(fn (): Reward => Reward::factory()->for($this->enrollmentA, 'enrollment')->create(['milestone' => 2, 'status' => RewardStatus::Expired]));
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect(fn () => DB::transaction(fn () => match ($how) {
            'un-redeem as the org admin' => $this->rewardA->forceFill(['status' => RewardStatus::Available, 'redeemed_at' => null, 'redeemed_business_id' => null])->save(),
            'credit it to a sibling' => $this->rewardA->forceFill(['redeemed_business_id' => $this->tenants->a2->id])->save(),
            'move the redemption time' => $this->rewardA->forceFill(['redeemed_at' => now()->subDay()])->save(),
            'expire it in a bulk update' => Reward::query()->update(['status' => RewardStatus::Expired->value]),
            'un-expire in bypass()' => $this->context->bypass(fn () => $expired->forceFill(['status' => RewardStatus::Available])->save()),
        }))->toThrow(QueryException::class, 'rewards_outcome_final');
    })->with(['un-redeem as the org admin', 'credit it to a sibling', 'move the redemption time', 'expire it in a bulk update', 'un-expire in bypass()']);

    it('keeps a card with members from being deleted', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect(fn () => DB::transaction(fn () => $this->tenants->cardA->delete()))->toThrow(QueryException::class);
    });

    it('keeps a business or location where a reward was redeemed from being deleted', function (string $what): void {
        $a2Location = $this->tenants->locationOf($this->tenants->a2);
        $this->context->bypass(fn () => $this->rewardA->forceFill([
            'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a2->id, 'redeemed_location_id' => $a2Location->id,
        ])->save());

        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($what) {
            'business' => $this->tenants->a2->delete(),
            'location' => $a2Location->delete(),
        })))->toThrow(QueryException::class);
    })->with(['business', 'location']);

    it('removes a customer\'s cards and rewards with their account, and keeps rewards a deleted staff member redeemed', function (): void {
        $staff = User::factory()->create();
        $this->context->bypass(fn () => $this->rewardA->forceFill([
            'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_by' => $staff->id, 'redeemed_business_id' => $this->tenants->a1->id,
        ])->save());

        $staff->delete();

        expect($this->context->bypass(fn (): ?int => $this->rewardA->refresh()->redeemed_by))->toBeNull();

        $this->customer->delete();

        $this->context->bypass(function (): void {
            expect(CardEnrollment::query()->count())->toBe(0)
                ->and(Reward::query()->count())->toBe(0);
        });
    });

    it('removes all program data with the organization', function (): void {
        $this->context->bypass(function (): void {
            $this->rewardA->forceFill(['status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id])->save();
            $this->tenants->orgA->delete();

            expect(CardEnrollment::query()->pluck('id')->all())->toBe([$this->enrollmentB->id])
                ->and(Reward::query()->count())->toBe(0);
        });
    });

    it('lets Postgres refuse an inconsistent reward or referral', function (string $case, string $constraint): void {
        $a1Location = $this->tenants->locationOf($this->tenants->a1);

        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($case) {
            'redemption fields on an available reward' => $this->rewardA->forceFill(['redeemed_business_id' => $this->tenants->a1->id, 'redeemed_location_id' => $a1Location->id])->save(),
            'redeemed at a location without its business' => $this->rewardA->forceFill(['status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_location_id' => $a1Location->id])->save(),
            'redeemed without a time' => $this->rewardA->forceFill(['status' => RewardStatus::Redeemed, 'redeemed_business_id' => $this->tenants->a1->id])->save(),
            // The model already refuses changing these; raw inserts reach the database.
            'a negative reward value' => Reward::query()->insert([
                'organization_id' => $this->tenants->orgA->id, 'enrollment_id' => $this->enrollmentA->id, 'mode' => 'cyclic', 'milestone' => 3,
                'reward_type' => 'fixed', 'reward_value' => -5, 'reward_text' => 'x', 'status' => 'available', 'unlocked_at' => now(),
            ]),
            'a referrer that is itself' => CardEnrollment::query()->insert([
                'id' => 999_999, 'organization_id' => $this->tenants->orgA->id, 'card_id' => $this->tenants->cardA->id,
                'user_id' => User::factory()->create()->id, 'referred_by' => 999_999,
            ]),
            'negative stamps' => $this->enrollmentA->forceFill(['current_stamps' => -2])->save(),
        })))->toThrow(QueryException::class, $constraint);
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'CHECK constraints are Postgres only')->with([
        'redemption fields on an available reward' => ['redemption fields on an available reward', 'rewards_unredeemed_check'],
        'redeemed at a location without its business' => ['redeemed at a location without its business', 'rewards_redeemed_check'],
        'redeemed without a time' => ['redeemed without a time', 'rewards_redeemed_check'],
        'a negative reward value' => ['a negative reward value', 'rewards_value_check'],
        'a referrer that is itself' => ['a referrer that is itself', 'card_enrollments_referral_check'],
        'negative stamps' => ['negative stamps', 'card_enrollments_counts_check'],
    ]);
});
