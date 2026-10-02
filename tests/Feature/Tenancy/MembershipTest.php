<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\OrganizationRole;
use App\Models\BusinessMember;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Memberships stay inside their tenant (ADR 0006)
|--------------------------------------------------------------------------
|
| Staff memberships (business_user) are site data; org admin memberships
| (organization_user) belong to the organization. Both go through guarded
| pivot models, so attach(), detach($ids) and updateExistingPivot() cannot
| cross a tenant boundary.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->user = User::factory()->create();
});

it('lets an owner add staff to their own business', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1);

    $this->tenants->a1->members()->attach($this->user, ['role' => BusinessRole::Staff->value]);

    $member = BusinessMember::query()->where('user_id', $this->user->id)->firstOrFail();

    expect($member->business_id)->toBe($this->tenants->a1->id)
        ->and($member->organization_id)->toBe($this->tenants->orgA->id);
});

it('refuses staff changes in another business', function (string $business): void {
    $other = $this->tenants->{$business};
    $this->context->set($this->tenants->orgA, $this->tenants->a1);

    $other->members()->attach($this->user, ['role' => BusinessRole::Staff->value]);
})->throws(LogicException::class)->with(['franchisee A2' => ['a2'], 'business B1' => ['b1']]);

it('refuses changing or removing staff of another business', function (string $how): void {
    $this->tenants->member($this->user, $this->tenants->b1);
    $this->context->set($this->tenants->orgA, $this->tenants->a1);

    match ($how) {
        'promote' => $this->tenants->b1->members()->updateExistingPivot($this->user->id, ['role' => BusinessRole::Owner->value]),
        'remove' => $this->tenants->b1->members()->detach([$this->user->id]),
    };
})->throws(LogicException::class)->with(['promote', 'remove']);

it('lets an org admin staff any business of the organization, not another one', function (): void {
    $this->context->set($this->tenants->orgA);

    $this->tenants->a2->members()->attach($this->user, ['role' => BusinessRole::Staff->value]);

    expect(BusinessMember::query()->where('user_id', $this->user->id)->value('business_id'))->toBe($this->tenants->a2->id);

    $this->tenants->b1->members()->attach(User::factory()->create(), ['role' => BusinessRole::Staff->value]);
})->throws(LogicException::class);

it('keeps a staff location inside the business', function (): void {
    $b1Location = $this->tenants->locationOf($this->tenants->b1);
    $this->context->set($this->tenants->orgA, $this->tenants->a1);

    $this->tenants->a1->members()->attach($this->user, ['role' => BusinessRole::Staff->value, 'location_id' => $b1Location->id]);
})->throws(QueryException::class);

it('lets only an org admin of the organization manage org admins', function (?string $business): void {
    $this->context->set($this->tenants->orgA, $business === null ? null : $this->tenants->{$business});

    $this->tenants->orgA->admins()->attach($this->user, ['role' => OrganizationRole::OrgAdmin->value]);

    expect(OrganizationMember::query()->where('user_id', $this->user->id)->value('organization_id'))->toBe($this->tenants->orgA->id);
})->with(['org admin' => [null]]);

it('refuses org admin changes from a franchisee or another organization', function (string $case): void {
    match ($case) {
        'franchisee A1' => $this->context->set($this->tenants->orgA, $this->tenants->a1),
        'org admin of B' => $this->context->set($this->tenants->orgB),
    };

    $this->tenants->orgA->admins()->attach($this->user, ['role' => OrganizationRole::OrgAdmin->value]);
})->throws(LogicException::class)->with(['franchisee A1', 'org admin of B']);

it('shows memberships only inside the tenant', function (): void {
    $this->tenants->member($this->user, $this->tenants->b1);
    $this->tenants->admin($this->user, $this->tenants->orgB);
    $this->context->set($this->tenants->orgA, $this->tenants->a1);

    expect(BusinessMember::query()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(OrganizationMember::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('guards every pivot write path of a business in another tenant', function (string $how): void {
    $staff = $this->tenants->member($this->user, $this->tenants->b1);
    $this->context->set($this->tenants->orgA, $this->tenants->a1);
    $members = $this->tenants->b1->members();

    match ($how) {
        'detach all' => $members->detach(),
        'detach where pivot' => $members->wherePivot('role', 'staff')->detach(),
        'sync' => $members->sync([]),
        'sync without detaching' => $members->syncWithoutDetaching([$staff->id => ['role' => 'owner']]),
        'toggle' => $members->toggle([$staff->id]),
        'update where pivot' => $members->wherePivot('role', 'staff')->updateExistingPivot($staff->id, ['role' => 'owner']),
        'attach with a pivot value' => $members->withPivotValue('role', 'owner')->attach(User::factory()->create()),
    };
})->throws(LogicException::class)->with([
    'detach all', 'detach where pivot', 'sync', 'sync without detaching', 'toggle', 'update where pivot', 'attach with a pivot value',
]);

it('never moves a membership to another user', function (): void {
    $this->tenants->member($this->user, $this->tenants->a1);
    $this->context->set($this->tenants->orgA, $this->tenants->a1);

    BusinessMember::query()->where('user_id', $this->user->id)->firstOrFail()
        ->forceFill(['user_id' => User::factory()->create()->id])->save();
})->throws(LogicException::class);

it('refuses changing or removing org admins from a franchisee or another organization', function (string $case, string $how): void {
    $this->tenants->admin($this->user, $this->tenants->orgA);

    match ($case) {
        'franchisee A1' => $this->context->set($this->tenants->orgA, $this->tenants->a1),
        'org admin of B' => $this->context->set($this->tenants->orgB),
    };

    match ($how) {
        'update' => $this->tenants->orgA->admins()->updateExistingPivot($this->user->id, ['updated_at' => now()->addDay()]),
        'remove' => $this->tenants->orgA->admins()->detach([$this->user->id]),
    };
})->throws(LogicException::class)->with(['franchisee A1', 'org admin of B'])->with(['update', 'remove']);

it('deletes a business or organization with staff at a location, but not the location itself', function (): void {
    $a1Location = $this->tenants->locationOf($this->tenants->a1);
    $this->context->bypass(fn () => $this->tenants->a1->members()->attach($this->user, ['role' => 'staff', 'location_id' => $a1Location->id]));
    $b1Staff = User::factory()->create();
    $b1Location = $this->tenants->locationOf($this->tenants->b1);
    $this->context->bypass(fn () => $this->tenants->b1->members()->attach($b1Staff, ['role' => 'staff', 'location_id' => $b1Location->id]));

    expect(fn () => $this->context->bypass(fn (): ?bool => $a1Location->delete()))->toThrow(QueryException::class);

    $this->context->bypass(function (): void {
        $this->tenants->a1->delete();
        $this->tenants->orgB->delete();
    });

    expect($this->context->bypass(fn (): int => BusinessMember::query()->count()))->toBe(0);
});
