<?php

declare(strict_types=1);

use App\Actions\Tenancy\ResolveTenant;
use App\Enums\BillingEntity;
use App\Enums\BusinessRole;
use App\Enums\CardMode;
use App\Enums\OrganizationType;
use App\Enums\RewardType;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Reward;
use App\Models\Stamper;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Site policies (CHW-22)
|--------------------------------------------------------------------------
|
| Organization A is a franchise (HQ, franchisees A1 and A2); organization B
| an independent café (B1), whose owner is its org admin. Each user's tenant
| is resolved as a request would (ResolveTenant), then asked. Rights come
| from where the user works now, never from a role held somewhere else.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $people = fn (BusinessRole $role, Business $business): User => $this->tenants->member(User::factory()->create(), $business, $role);

    $this->hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->ownerA1 = $people(BusinessRole::Owner, $this->tenants->a1);
    $this->staffA1 = $people(BusinessRole::Staff, $this->tenants->a1);
    $this->ownerA2 = $people(BusinessRole::Owner, $this->tenants->a2);
    $this->ownerB1 = $people(BusinessRole::Owner, $this->tenants->b1);
    $this->customer = User::factory()->create();

    // A1 has a second site; one staff member works only there.
    $this->a1Site = $this->tenants->locationOf($this->tenants->a1);
    $this->a1Terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['name' => 'A1 terrace']));
    $this->terraceStaff = $people(BusinessRole::Staff, $this->tenants->a1);
    $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $this->terraceStaff->id)->update(['location_id' => $this->a1Terrace->id]));

    $this->stamperA1 = $this->tenants->stamper($this->tenants->a1);
    $this->terraceStamper = $this->context->bypass(fn (): Stamper => Stamper::factory()->create(['business_id' => $this->tenants->a1->id, 'location_id' => $this->a1Terrace->id]));

    $this->as = function (User $user, ?string $choice = null): GateContract {
        app(ResolveTenant::class)->handle($user, $choice);

        return Gate::forUser($user);
    };
    $this->allowed = fn (User $user, string $ability, mixed $arguments): bool => ($this->as)($user)->allows($ability, $arguments);
});

it('lets HQ run the franchise, never a franchisee', function (): void {
    $orgA = $this->tenants->orgA;

    expect(($this->allowed)($this->hq, 'update', $orgA))->toBeTrue()
        ->and(($this->allowed)($this->hq, 'manageBrand', $orgA))->toBeTrue()
        ->and(($this->allowed)($this->hq, 'viewNetwork', $orgA))->toBeTrue()
        ->and(($this->allowed)($this->hq, 'inviteFranchisee', $orgA))->toBeTrue()
        ->and(($this->allowed)($this->ownerA1, 'view', $orgA))->toBeTrue()
        ->and(($this->allowed)($this->ownerA1, 'update', $orgA))->toBeFalse()
        ->and(($this->allowed)($this->ownerA1, 'manageBrand', $orgA))->toBeFalse()
        ->and(($this->allowed)($this->ownerA1, 'viewNetwork', $orgA))->toBeFalse()
        ->and(($this->allowed)($this->staffA1, 'update', $orgA))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, 'view', $orgA))->toBeFalse()
        ->and(($this->allowed)($this->customer, 'view', $orgA))->toBeFalse();
});

it('lets the owner of an independent café run its organization, card and brand', function (): void {
    $orgB = $this->tenants->orgB;
    $secondOwner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);

    foreach ([$this->ownerB1, $secondOwner] as $owner) {
        expect(($this->allowed)($owner, 'update', $orgB))->toBeTrue()
            ->and(($this->allowed)($owner, 'manageBrand', $orgB))->toBeTrue()
            ->and(($this->allowed)($owner, 'viewNetwork', $orgB))->toBeFalse()
            ->and($this->context->isOrgAdmin())->toBeTrue();
    }

    // The second owner has no org_admin row, yet edits the café's own card (CHW-22 comment).
    ($this->as)($secondOwner);
    $this->tenants->cardB->forceFill(['name' => 'Renamed'])->save();

    expect($this->context->bypass(fn (): string => LoyaltyCard::query()->findOrFail($this->tenants->cardB->id)->name))->toBe('Renamed');
});

it('gives billing to whoever pays', function (BillingEntity $payer): void {
    $this->context->bypass(fn () => $this->tenants->orgA->forceFill(['billing_entity' => $payer])->save());
    $orgA = $this->tenants->orgA->fresh();

    expect(($this->allowed)($this->hq, 'manageBilling', $orgA))->toBe($payer === BillingEntity::Organization)
        ->and(($this->allowed)($this->ownerA1, 'manageBilling', $this->tenants->a1))->toBe($payer === BillingEntity::Business)
        ->and(($this->allowed)($this->hq, 'manageBilling', $this->tenants->a1))->toBeFalse()
        ->and(($this->allowed)($this->staffA1, 'manageBilling', $this->tenants->a1))->toBeFalse()
        ->and(($this->allowed)($this->ownerA2, 'manageBilling', $this->tenants->a1))->toBeFalse();
})->with([BillingEntity::Organization, BillingEntity::Business]);

it('lets a business\'s owner or org admin run it, its staff only see it, and nobody else', function (string $ability): void {
    $a1 = $this->tenants->a1;
    $runs = $ability !== 'view';

    expect(($this->allowed)($this->ownerA1, $ability, $a1))->toBeTrue()
        ->and(($this->allowed)($this->hq, $ability, $a1))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, $ability, $a1))->toBe(! $runs)
        ->and(($this->allowed)($this->ownerA2, $ability, $a1))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, $ability, $a1))->toBeFalse()
        ->and(($this->allowed)($this->customer, $ability, $a1))->toBeFalse();
})->with(['view', 'update', 'viewStats', 'viewCustomers']);

it('lets only the owner or org admin change a location, its timezone included', function (string $ability): void {
    $arguments = $ability === 'create' ? [Location::class, $this->tenants->a1] : $this->a1Site;

    expect(($this->allowed)($this->ownerA1, $ability, $arguments))->toBeTrue()
        ->and(($this->allowed)($this->hq, $ability, $arguments))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, $ability, $arguments))->toBeFalse()
        ->and(($this->allowed)($this->ownerA2, $ability, $arguments))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, $ability, $arguments))->toBeFalse()
        ->and(($this->allowed)($this->staffA1, 'view', $this->a1Site))->toBeTrue()
        ->and(($this->allowed)($this->ownerA2, 'view', $this->a1Site))->toBeFalse();
})->with(['create', 'update', 'delete']);

it('lets anyone at the counter arm a stamper, staff limited to a site only there', function (): void {
    expect(($this->allowed)($this->ownerA1, 'arm', $this->stamperA1))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, 'arm', $this->stamperA1))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, 'arm', $this->terraceStamper))->toBeTrue()
        ->and(($this->allowed)($this->terraceStaff, 'arm', $this->terraceStamper))->toBeTrue()
        ->and(($this->allowed)($this->terraceStaff, 'arm', $this->stamperA1))->toBeFalse()
        ->and(($this->allowed)($this->ownerA2, 'arm', $this->stamperA1))->toBeFalse()
        ->and(($this->allowed)($this->hq, 'arm', $this->stamperA1))->toBeFalse()
        ->and(($this->allowed)($this->customer, 'arm', $this->stamperA1))->toBeFalse();
});

it('lets only the owner or org admin rename, disable or move a stamper', function (): void {
    expect(($this->allowed)($this->ownerA1, 'update', $this->stamperA1))->toBeTrue()
        ->and(($this->allowed)($this->hq, 'update', $this->stamperA1))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, 'update', $this->stamperA1))->toBeFalse()
        ->and(($this->allowed)($this->ownerA2, 'update', $this->stamperA1))->toBeFalse()
        ->and(($this->allowed)($this->staffA1, 'view', $this->stamperA1))->toBeTrue()
        ->and(($this->allowed)($this->ownerB1, 'view', $this->stamperA1))->toBeFalse();
});

it('lets the owner or org admin manage people, and always keeps an owner', function (): void {
    $a1 = $this->tenants->a1;
    $member = fn (User $user): BusinessMember => $this->context->bypass(fn (): BusinessMember => BusinessMember::query()->where('user_id', $user->id)->firstOrFail());

    expect(($this->allowed)($this->ownerA1, 'viewAny', [BusinessMember::class, $a1]))->toBeTrue()
        ->and(($this->allowed)($this->hq, 'create', [BusinessMember::class, $a1]))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, 'viewAny', [BusinessMember::class, $a1]))->toBeFalse()
        ->and(($this->allowed)($this->ownerA2, 'viewAny', [BusinessMember::class, $a1]))->toBeFalse()
        ->and(($this->allowed)($this->ownerA1, 'delete', $member($this->staffA1)))->toBeTrue()
        ->and(($this->allowed)($this->staffA1, 'delete', $member($this->terraceStaff)))->toBeFalse()
        // The last owner is never removed or made staff.
        ->and(($this->allowed)($this->hq, 'delete', $member($this->ownerA1)))->toBeFalse()
        ->and(($this->allowed)($this->hq, 'changeRole', [$member($this->ownerA1), BusinessRole::Staff]))->toBeFalse()
        ->and(($this->allowed)($this->ownerA1, 'changeRole', [$member($this->staffA1), BusinessRole::Owner]))->toBeTrue();

    $this->tenants->member(User::factory()->create(), $a1, BusinessRole::Owner);

    expect(($this->allowed)($this->hq, 'delete', $member($this->ownerA1)))->toBeTrue()
        ->and(($this->allowed)($this->hq, 'changeRole', [$member($this->ownerA1), BusinessRole::Staff]))->toBeTrue();
});

it('keeps HQ\'s people from franchisees, and always keeps an org admin', function (): void {
    $orgA = $this->tenants->orgA;
    $hqRow = fn (User $user): OrganizationMember => $this->context->bypass(fn (): OrganizationMember => OrganizationMember::query()->where('user_id', $user->id)->firstOrFail());

    expect(($this->allowed)($this->hq, 'viewAny', [OrganizationMember::class, $orgA]))->toBeTrue()
        ->and(($this->allowed)($this->ownerA1, 'viewAny', [OrganizationMember::class, $orgA]))->toBeFalse()
        ->and(($this->allowed)($this->staffA1, 'create', [OrganizationMember::class, $orgA]))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, 'viewAny', [OrganizationMember::class, $orgA]))->toBeFalse()
        ->and(($this->allowed)($this->hq, 'delete', $hqRow($this->hq)))->toBeFalse();

    $second = $this->tenants->admin(User::factory()->create(), $orgA);

    expect(($this->allowed)($this->hq, 'delete', $hqRow($second)))->toBeTrue();
});

it('judges rights where the user works now, not where they hold a role', function (): void {
    // HQ of A who also owns B1: in B1 they are B1's owner, never A's org admin.
    $this->tenants->member($this->hq, $this->tenants->b1, BusinessRole::Owner);

    expect(($this->as)($this->hq, 'business:'.$this->tenants->b1->id)->allows('update', $this->tenants->orgA))->toBeFalse()
        ->and(($this->as)($this->hq, 'business:'.$this->tenants->b1->id)->allows('update', $this->tenants->b1))->toBeTrue()
        ->and(($this->as)($this->hq, 'org:'.$this->tenants->orgA->id)->allows('update', $this->tenants->orgA))->toBeTrue();
});

it('never makes a franchisee the org admin, even the only one so far', function (): void {
    [$franchise, $only] = $this->context->bypass(function (): array {
        $franchise = Organization::factory()->franchise()->create();

        return [$franchise, Business::factory()->for($franchise)->create()];
    });
    $owner = $this->tenants->member(User::factory()->create(), $only, BusinessRole::Owner);

    expect(($this->allowed)($owner, 'update', $franchise))->toBeFalse()
        ->and($this->context->isOrgAdmin())->toBeFalse()
        ->and(($this->allowed)($owner, 'update', $only))->toBeTrue();
});

it('gives HQ working inside a franchisee none of the organization\'s rights there', function (): void {
    // HQ who also runs A1: inside A1 they act for A1, not for the franchise.
    $this->tenants->member($this->hq, $this->tenants->a1, BusinessRole::Owner);
    $inA1 = 'business:'.$this->tenants->a1->id;
    $orgA = $this->tenants->orgA;

    foreach (['update', 'manageBrand', 'viewNetwork', 'inviteFranchisee'] as $ability) {
        expect(($this->as)($this->hq, $inA1)->allows($ability, $orgA))->toBeFalse()
            ->and(($this->as)($this->hq, 'org:'.$orgA->id)->allows($ability, $orgA))->toBeTrue();
    }

    expect(($this->as)($this->hq, $inA1)->allows('create', [OrganizationMember::class, $orgA]))->toBeFalse()
        ->and(($this->as)($this->hq, $inA1)->allows('update', $this->tenants->a1))->toBeTrue();
});

it('runs another franchisee only from across the organization, not from inside a business', function (): void {
    // HQ who also runs A2: working in A2, A1 is not theirs to run.
    $this->tenants->member($this->hq, $this->tenants->a2, BusinessRole::Owner);
    $inA2 = 'business:'.$this->tenants->a2->id;

    expect(($this->as)($this->hq, $inA2)->allows('update', $this->tenants->a1))->toBeFalse()
        ->and(($this->as)($this->hq, $inA2)->allows('view', $this->tenants->a1))->toBeFalse()
        ->and(($this->as)($this->hq, 'org:'.$this->tenants->orgA->id)->allows('update', $this->tenants->a1))->toBeTrue();
});

it('never lets a co-owner grant themselves an org admin row that would outlive them', function (): void {
    $coOwner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);
    $orgB = $this->tenants->orgB;

    expect(($this->allowed)($coOwner, 'create', [OrganizationMember::class, $orgB]))->toBeFalse()
        ->and(($this->allowed)($coOwner, 'viewAny', [OrganizationMember::class, $orgB]))->toBeFalse()
        ->and($this->context->isOrgAdmin())->toBeTrue()
        ->and($this->context->managesOrgAdmins())->toBeFalse()
        ->and(fn () => $orgB->admins()->attach($coOwner, ['role' => 'org_admin']))->toThrow(LogicException::class, 'Only an org admin of the organization manages its org admins.');

    // Removed from the café, the co-owner keeps nothing.
    $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $coOwner->id)->delete());
    ($this->as)($coOwner);

    expect($this->context->organizationId())->toBeNull()
        ->and(($this->allowed)($coOwner, 'update', $orgB))->toBeFalse();
});

it('makes a chain\'s owner its org admin only while it has one business', function (): void {
    [$chain, $first] = $this->context->bypass(function (): array {
        $chain = Organization::factory()->create(['type' => OrganizationType::Chain]);

        return [$chain, Business::factory()->for($chain)->create()];
    });
    $owner = $this->tenants->member(User::factory()->create(), $first, BusinessRole::Owner);

    expect(($this->allowed)($owner, 'update', $chain))->toBeTrue();

    // A second business, even archived, makes the organization more than the account.
    $this->context->bypass(fn () => Business::factory()->for($chain)->create(['archived_at' => now()]));

    expect(($this->allowed)($owner, 'update', $chain))->toBeFalse()
        ->and(($this->allowed)($owner, 'update', $first))->toBeTrue();
});

it('gives the owner of another organization nothing in A', function (): void {
    $hqRow = $this->context->bypass(fn (): OrganizationMember => OrganizationMember::query()->where('user_id', $this->hq->id)->firstOrFail());

    foreach (['arm', 'update', 'view'] as $ability) {
        expect(($this->allowed)($this->ownerB1, $ability, $this->stamperA1))->toBeFalse();
    }

    expect(($this->allowed)($this->ownerB1, 'viewAny', [BusinessMember::class, $this->tenants->a1]))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, 'create', [BusinessMember::class, $this->tenants->a1]))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, 'manageBilling', $this->tenants->a1))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, 'manageBilling', $this->tenants->orgA))->toBeFalse()
        ->and(($this->allowed)($this->ownerB1, 'delete', $hqRow))->toBeFalse()
        ->and(($this->allowed)($this->ownerA1, 'delete', $hqRow))->toBeFalse();
});

it('lets nobody create a reward outside the stamp Action, an org admin included', function (): void {
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    ($this->as)($this->ownerB1);

    expect(fn () => Reward::query()->create(['enrollment_id' => $enrollment->id, 'mode' => CardMode::Cyclic, 'milestone' => 1, 'reward_type' => RewardType::Item, 'reward_text' => 'x', 'unlocked_at' => now()]))
        ->toThrow(LogicException::class, 'A reward is unlocked by the stamp Action');
});

it('carries a staff member\'s site limit, and nobody else\'s', function (): void {
    ($this->as)($this->terraceStaff);
    expect($this->context->locationId())->toBe($this->a1Terrace->id);

    ($this->as)($this->ownerA1);
    expect($this->context->locationId())->toBeNull();
});
