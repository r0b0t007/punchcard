<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Enums\RewardType;
use App\Models\CardEnrollment;
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
| customer data inside it. The org admin sees every member of the program.
| Inside a business they stay hidden for now: a franchisee may only list
| customers who stamped there, and that rule needs stamp_events (CHW-21
| PR C), so until then a business sees none rather than every member.
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

    it('shows a business no customers until it can tell who stamped there (PR C)', function (string $business): void {
        $this->context->set($business === 'b1' ? $this->tenants->orgB : $this->tenants->orgA, $this->tenants->{$business}, orgAdmin: true, businessRole: BusinessRole::Owner);

        expect(CardEnrollment::query()->count())->toBe(0)
            ->and(Reward::query()->count())->toBe(0);
    })->with(['a1', 'a2', 'b1']);

    it('shows nothing without a tenant', function (): void {
        expect(CardEnrollment::query()->count())->toBe(0)
            ->and(Reward::query()->count())->toBe(0);
    });
});

describe('writes', function (): void {
    it('lets a franchisee enroll a customer on the shared card, in its organization', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

        $enrollment = CardEnrollment::query()->create(['card_id' => $this->tenants->cardA->id, 'user_id' => User::factory()->create()->id]);

        expect($enrollment->organization_id)->toBe($this->tenants->orgA->id);
    });

    it('refuses an enrollment or a reward in another organization', function (string $how): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        match ($how) {
            'enrollment on B\'s card' => CardEnrollment::query()->create(['card_id' => $this->tenants->cardB->id, 'user_id' => User::factory()->create()->id]),
            'reward on B\'s enrollment' => Reward::query()->create(['enrollment_id' => $this->enrollmentB->id, 'milestone' => 1, 'reward_type' => RewardType::Item, 'reward_text' => 'x', 'unlocked_at' => now()]),
        };
    })->throws(LogicException::class, 'for another organization')->with(['enrollment on B\'s card', 'reward on B\'s enrollment']);

    it('needs a tenant or bypass() to enroll', function (): void {
        CardEnrollment::query()->create(['card_id' => $this->tenants->cardA->id, 'user_id' => User::factory()->create()->id]);
    })->throws(LogicException::class);

    it('lets the org admin update progress and redeem a reward', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $this->enrollmentA->update(['current_stamps' => 3, 'lifetime_stamps' => 13, 'last_stamp_at' => now()]);
        $this->rewardA->update(['status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a2->id]);

        expect($this->enrollmentA->refresh()->current_stamps)->toBe(3)
            ->and($this->rewardA->refresh()->status)->toBe(RewardStatus::Redeemed);
    });

    it('never moves an enrollment or a reward, even in bypass()', function (string $how): void {
        $this->context->bypass(fn () => match ($how) {
            'enrollment to another customer' => $this->enrollmentA->update(['user_id' => User::factory()->create()->id]),
            'enrollment to another card' => $this->enrollmentA->update(['card_id' => $this->tenants->cardB->id]),
            'reward to another enrollment' => $this->rewardA->update(['enrollment_id' => $this->enrollmentB->id]),
            'reward milestone' => $this->rewardA->update(['milestone' => 2]),
            'reward value after unlock' => $this->rewardA->update(['reward_text' => 'Something cheaper']),
            'bulk' => CardEnrollment::query()->update(['user_id' => $this->customer->id]),
        });
    })->throws(LogicException::class)->with([
        'enrollment to another customer', 'enrollment to another card', 'reward to another enrollment',
        'reward milestone', 'reward value after unlock', 'bulk',
    ]);

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

    it('creates one reward per milestone', function (): void {
        expect(fn () => DB::transaction(fn () => $this->tenants->reward($this->enrollmentA)))
            ->toThrow(QueryException::class);
    });

    it('lets the database refuse a redemption outside the reward\'s organization or business, even in bypass()', function (string $how): void {
        $b1Location = $this->tenants->locationOf($this->tenants->b1);
        $a2Location = $this->tenants->locationOf($this->tenants->a2);

        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => match ($how) {
            'at another organization\'s business' => $this->rewardA->update(['redeemed_business_id' => $this->tenants->b1->id, 'redeemed_location_id' => $b1Location->id]),
            'at a location of another business' => $this->rewardA->update(['redeemed_business_id' => $this->tenants->a1->id, 'redeemed_location_id' => $a2Location->id]),
        })))->toThrow(QueryException::class);
    })->with(['at another organization\'s business', 'at a location of another business']);

    it('keeps a card with members from being deleted', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect(fn () => DB::transaction(fn () => $this->tenants->cardA->delete()))->toThrow(QueryException::class);
    });

    it('keeps a business where a reward was redeemed from being deleted', function (): void {
        $this->context->bypass(fn () => $this->rewardA->update(['status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a2->id]));
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect(fn () => DB::transaction(fn () => $this->tenants->a2->delete()))->toThrow(QueryException::class);
    });

    it('removes all program data with the organization', function (): void {
        $this->context->bypass(function (): void {
            $this->rewardA->update(['status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id]);
            $this->tenants->orgA->delete();

            expect(CardEnrollment::query()->pluck('id')->all())->toBe([$this->enrollmentB->id])
                ->and(Reward::query()->count())->toBe(0);
        });
    });

    it('keeps a referral when the referrer leaves', function (): void {
        $friend = $this->context->bypass(fn (): CardEnrollment => CardEnrollment::query()->create([
            'card_id' => $this->tenants->cardA->id, 'user_id' => User::factory()->create()->id, 'referred_by' => $this->enrollmentA->id,
        ]));

        $this->context->bypass(function () use ($friend): void {
            $this->enrollmentA->delete();

            expect($friend->refresh()->referred_by)->toBeNull();
        });
    });

    it('lets Postgres refuse an inconsistent reward', function (array $values): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(fn () => $this->rewardA->update($values))))
            ->toThrow(QueryException::class);
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'CHECK constraints are Postgres only')->with([
        'a location without its business' => [['redeemed_location_id' => 1]],
        'redeemed without a business' => [['status' => 'redeemed', 'redeemed_at' => now()]],
        'redeemed without a time' => [['status' => 'redeemed', 'redeemed_business_id' => 1]],
    ]);
});
