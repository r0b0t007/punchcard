<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Enums\BusinessRole;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Follows: who agreed to hear about a program's offers (CHW-27)
|--------------------------------------------------------------------------
|
| Customer data of the organization, like enrollments: its org admin reads
| all of its followers, a franchisee only those who stamped there, another
| organization none. Only the customer sets their own follow (SetFollow, in
| bypass()); nobody moves it to another customer or program, and deleting
| an account takes its follows with it.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->follow = fn (User $user, Organization $organization, bool $notify = true): Follow => $this->context->bypass(
        fn (): Follow => Follow::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'notify' => $notify]),
    );
    $this->readAs = function (Organization $organization, ?Business $business = null, bool $orgAdmin = false): array {
        $this->context->set($organization, $business, orgAdmin: $orgAdmin);

        return Follow::query()->orderBy('id')->pluck('user_id')->all();
    };
    // Followers of the franchise: one who stamped at A1, one only at A2.
    $this->atA1 = User::factory()->create();
    $this->onlyA2 = User::factory()->create();
    $this->tenants->stamp($this->tenants->enroll($this->atA1, $this->tenants->cardA), $this->tenants->a1);
    $this->tenants->stamp($this->tenants->enroll($this->onlyA2, $this->tenants->cardA), $this->tenants->a2);
    ($this->follow)($this->atA1, $this->tenants->orgA);
    ($this->follow)($this->onlyA2, $this->tenants->orgA);
    $this->ofB = User::factory()->create();
    ($this->follow)($this->ofB, $this->tenants->orgB);
});

it('shows the org admin every follower of their program, never another organization\'s', function (): void {
    expect(($this->readAs)($this->tenants->orgA, orgAdmin: true))->toBe([$this->atA1->id, $this->onlyA2->id])
        ->and(($this->readAs)($this->tenants->orgB, orgAdmin: true))->toBe([$this->ofB->id]);
});

it('shows a franchisee only the followers who stamped there', function (): void {
    expect(($this->readAs)($this->tenants->orgA, $this->tenants->a1))->toBe([$this->atA1->id])
        ->and(($this->readAs)($this->tenants->orgA, $this->tenants->a2))->toBe([$this->onlyA2->id]);
});

it('lets no business create, move or delete a follow: only the customer, in bypass()', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true);

    expect(fn () => Follow::query()->create(['user_id' => User::factory()->create()->id, 'organization_id' => $this->tenants->orgA->id]))
        ->toThrow(LogicException::class);

    $follow = Follow::query()->where('user_id', $this->atA1->id)->firstOrFail();
    expect(fn () => $follow->forceFill(['notify' => false])->save())->toThrow(LogicException::class)
        ->and(fn () => $follow->delete())->toThrow(LogicException::class);

    $this->context->clear();
    expect(fn () => $this->context->bypass(fn () => $follow->forceFill(['user_id' => $this->ofB->id])->save()))->toThrow(LogicException::class)
        ->and(fn () => $this->context->bypass(fn () => $follow->forceFill(['organization_id' => $this->tenants->orgB->id])->save()))->toThrow(LogicException::class);
});

it('takes a deleted account\'s follows with it, kept history or not', function (): void {
    // With stamps the account is anonymised and kept; without, the user row goes.
    app(DeleteAccount::class)->handle($this->atA1);
    app(DeleteAccount::class)->handle($this->ofB);

    expect($this->context->bypass(fn (): array => Follow::query()->pluck('user_id')->all()))->toBe([$this->onlyA2->id]);
});

it('keeps one follow per customer and program', function (): void {
    expect(fn () => ($this->follow)($this->atA1, $this->tenants->orgA, false))->toThrow(UniqueConstraintViolationException::class);
});

it('lets staff of the franchisee see no more than its owner', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

    expect($staff)->not->toBeNull()
        ->and(Follow::query()->pluck('user_id')->all())->toBe([$this->atA1->id]);
});

it('never shows a franchisee a follower of another program, even one who stamped there', function (): void {
    // ofB follows org B only, and also holds the franchise's card with a stamp at A1.
    $this->tenants->stamp($this->tenants->enroll($this->ofB, $this->tenants->cardA), $this->tenants->a1);

    expect(($this->readAs)($this->tenants->orgA, $this->tenants->a1))->toBe([$this->atA1->id]);
});

it('narrows to a business\'s followers whoever asks, not only inside that business', function (): void {
    // Only a stamp in person, on a card of the program followed, counts: a bonus at A1 does not,
    // nor a stamp at A1 by someone who follows another program.
    $onlyA2Card = $this->context->bypass(fn (): CardEnrollment => CardEnrollment::query()->where('user_id', $this->onlyA2->id)->sole());
    $this->tenants->stamp($onlyA2Card, $this->tenants->a1, ['source' => StampSource::Bonus, 'staff_id' => null, 'idempotency_key' => null]);
    $this->tenants->stamp($this->tenants->enroll($this->ofB, $this->tenants->cardA), $this->tenants->a1);

    $followersOf = fn (Business $business): array => $this->context->bypass(function () use ($business): array {
        $query = Follow::query();
        (new Follow)->constrainToBusiness($query, $business->id);

        return $query->orderBy('id')->pluck('user_id')->all();
    });

    expect($followersOf($this->tenants->a1))->toBe([$this->atA1->id])
        ->and($followersOf($this->tenants->a2))->toBe([$this->onlyA2->id]);
});
