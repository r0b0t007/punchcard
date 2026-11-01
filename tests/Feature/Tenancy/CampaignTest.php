<?php

declare(strict_types=1);

use App\Actions\Tenancy\ResolveTenant;
use App\Enums\BusinessRole;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Campaigns (CHW-42, B9)
|--------------------------------------------------------------------------
|
| A campaign is site data: a franchisee sees its own, the org admin the
| organization's, never another organization's, and franchisee A1 never
| sees A2's. Its owner creates it scheduled; its status and counts are its
| delivery's, in bypass(), and it is never deleted.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->campaigns = $this->context->bypass(fn (): array => [
        'a1' => Campaign::factory()->for($this->tenants->a1)->create(),
        'a2' => Campaign::factory()->for($this->tenants->a2)->create(),
        'b1' => Campaign::factory()->for($this->tenants->b1)->create(),
    ]);
});

it('shows a franchisee its own campaigns, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
    $expected = match ($tenant) {
        'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), ['a1']],
        'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), ['a2']],
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), ['b1']],
        'no tenant' => [null, []],
    };

    expect(Campaign::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->campaigns[$key]->id, $expected[1]));
})->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);

it('never lets a franchisee reach another franchisee\'s or organization\'s campaign', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => Campaign::query()->findOrFail($this->campaigns[$other]->id))->toThrow(ModelNotFoundException::class);
})->with(['a2', 'b1']);

it('lets the owner create a campaign scheduled only, for their own business', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => Campaign::factory()->for($this->tenants->a2)->create())->toThrow(LogicException::class, 'another business')
        ->and(fn () => Campaign::factory()->for($this->tenants->a1)->create(['status' => CampaignStatus::Sent, 'sent_at' => now()]))->toThrow(LogicException::class, 'created scheduled')
        ->and(fn () => Campaign::factory()->for($this->tenants->a1)->create(['sent_count' => 500]))->toThrow(LogicException::class, 'unsent');

    expect(Campaign::factory()->for($this->tenants->a1)->create()->status)->toBe(CampaignStatus::Scheduled);
});

it('leaves a campaign\'s status and counts to its delivery, and never deletes one', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);
    $campaign = Campaign::query()->findOrFail($this->campaigns['a1']->id);

    expect(fn () => $campaign->forceFill(['sent_count' => 500])->save())->toThrow(LogicException::class, 'delivery only')
        ->and(fn () => Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sent->value]))->toThrow(LogicException::class, 'delivery only')
        ->and(fn () => $campaign->delete())->toThrow(LogicException::class, 'kept');

    $this->context->bypass(fn () => Campaign::query()->whereKey($campaign->id)->update(['sent_count' => 3]));
    expect($campaign->refresh()->sent_count)->toBe(3);
});

it('keeps what a campaign said, to whom and from whom, and the campaign itself, even in bypass()', function (string $column, mixed $value): void {
    $this->context->bypass(fn () => Campaign::query()->whereKey($this->campaigns['a1']->id)->update([$column => $value]));
})->throws(LogicException::class, 'never change')->with([
    'its title' => ['title', 'Something else'],
    'its message' => ['body', 'Something else'],
    'its audience' => ['segment', 'top_10'],
    'its card' => ['card_id', null],
    'its author' => ['created_by', null],
    'its idempotency key' => ['idempotency_key', 'another key'],
]);

it('never deletes a campaign, even in bypass()', function (): void {
    $this->context->bypass(fn () => Campaign::query()->findOrFail($this->campaigns['a1']->id)->delete());
})->throws(LogicException::class, 'kept');

it('lets only who runs the business see and send its campaigns', function (string $who, string $business, bool $allowed): void {
    $people = [
        'owner of A1' => fn (): User => $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner),
        'staff of A1' => fn (): User => $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff),
        'owner of B1' => fn (): User => $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner),
    ];
    $user = $people[$who]();
    app(ResolveTenant::class)->handle($user);
    $gate = Gate::forUser($user);

    expect($gate->allows('create', [Campaign::class, $this->tenants->{$business}]))->toBe($allowed)
        ->and($gate->allows('viewAny', [Campaign::class, $this->tenants->{$business}]))->toBe($allowed)
        ->and($gate->allows('view', $this->campaigns[$business]))->toBe($allowed);
})->with([
    'the owner, for their business' => ['owner of A1', 'a1', true],
    'staff' => ['staff of A1', 'a1', false],
    'the owner, for a sister franchisee' => ['owner of A1', 'a2', false],
    'another organization\'s owner' => ['owner of B1', 'a1', false],
]);

it('lets the franchise HQ working in a franchisee send its campaigns, never another franchisee\'s', function (): void {
    $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true);
    $gate = Gate::forUser($hq);

    expect($gate->allows('create', [Campaign::class, $this->tenants->a1]))->toBeTrue()
        ->and($gate->allows('view', $this->campaigns['a1']))->toBeTrue()
        ->and($gate->allows('create', [Campaign::class, $this->tenants->a2]))->toBeFalse()
        ->and($gate->allows('view', $this->campaigns['b1']))->toBeFalse();
});
