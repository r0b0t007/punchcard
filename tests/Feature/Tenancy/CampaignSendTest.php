<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Who a campaign went to (CHW-48, B10)
|--------------------------------------------------------------------------
|
| Site data: a franchisee sees its own, the org admin the organization's,
| never another organization's, and franchisee A1 never sees A2's, even
| for the same person. A send is kept as claimed, once per campaign and
| follower, with only its sent_at and opened_at filled in later, once each.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->user = User::factory()->create();
    $this->campaigns = [];
    $this->sends = [];

    foreach (['a1' => $this->tenants->a1, 'a2' => $this->tenants->a2, 'b1' => $this->tenants->b1] as $key => $business) {
        $this->campaigns[$key] = $this->context->bypass(fn (): Campaign => Campaign::factory()->for($business)->create());
        $this->sends[$key] = $this->context->bypass(fn (): CampaignSend => CampaignSend::factory()->create(['campaign_id' => $this->campaigns[$key]->id, 'user_id' => $this->user->id]));
    }
});

it('shows a franchisee its own sends, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
    $visible = (match ($tenant) {
        'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), ['a1']],
        'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), ['a2']],
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), ['b1']],
        'no tenant' => [null, []],
    })[1];

    expect(CampaignSend::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->sends[$key]->id, $visible));
})->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);

it('never lets a franchisee reach another franchisee\'s or organization\'s send', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => CampaignSend::query()->findOrFail($this->sends[$other]->id))->toThrow(ModelNotFoundException::class);
})->with(['a2', 'b1']);

it('claims a send only against its own business\'s campaign', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => DB::transaction(fn () => CampaignSend::query()->create(['campaign_id' => $this->campaigns[$other]->id, 'user_id' => User::factory()->create()->id])))
        ->toThrow(QueryException::class);
})->with(['a2', 'b1']);

it('makes a send for its campaign\'s business and organization', function (): void {
    expect($this->sends['a2']->only(['business_id', 'organization_id']))
        ->toBe(['business_id' => $this->tenants->a2->id, 'organization_id' => $this->tenants->orgA->id])
        ->and($this->sends['b1']->organization_id)->toBe($this->tenants->orgB->id);
});

it('claims a send once per campaign and follower', function (): void {
    expect(fn () => $this->context->bypass(fn () => DB::transaction(fn () => CampaignSend::factory()->create(['campaign_id' => $this->campaigns['a1']->id, 'user_id' => $this->user->id]))))
        ->toThrow(QueryException::class);
});

it('marks a send sent and opened once each, the model with it, and says so', function (): void {
    $send = $this->sends['a1'];
    $first = now()->startOfSecond();

    expect($this->context->bypass(fn (): bool => $send->markSent($first)))->toBeTrue()
        ->and($this->context->bypass(fn (): bool => $send->markOpened($first->addMinute())))->toBeTrue()
        ->and($send->isDirty())->toBeFalse()
        ->and($this->context->bypass(fn (): bool => $this->sends['a1']->markSent($first->addHour())))->toBeFalse()
        ->and($this->context->bypass(fn (): bool => $this->sends['a1']->markOpened($first->addHour())))->toBeFalse()
        ->and($send->refresh()->sent_at?->equalTo($first))->toBeTrue()
        ->and($send->opened_at?->equalTo($first->addMinute()))->toBeTrue();
});

it('fills in sent_at and opened_at once, and never changes anything else, even past the model', function (array $values): void {
    $send = fn (): QueryBuilder => DB::table('campaign_sends')->where('id', $this->sends['a1']->id);
    $send()->update(['sent_at' => now(), 'opened_at' => now()]);

    expect(fn () => DB::transaction(fn () => $send()->update($values)))->toThrow(QueryException::class, 'filled_once');
})->with([
    'sent again' => [['sent_at' => '2027-01-01 00:00:00']],
    'opened again' => [['opened_at' => '2027-01-01 00:00:00']],
    'cleared' => [['opened_at' => null]],
    'when it was claimed' => [['created_at' => '2020-01-01 00:00:00']],
]);

it('keeps a send as claimed, and deletes it only with the customer, in bypass', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => $this->sends['a1']->update(['user_id' => User::factory()->create()->id]))->toThrow(LogicException::class, 'as claimed')
        ->and(fn () => $this->sends['a1']->delete())->toThrow(LogicException::class, 'as claimed');

    expect($this->context->bypass(fn (): ?bool => $this->sends['a2']->delete()))->toBeTrue();
});

it('goes with the customer\'s account, and with the business, the campaigns kept', function (): void {
    $this->context->bypass(fn () => $this->user->delete());

    expect($this->context->bypass(fn (): int => CampaignSend::query()->count()))->toBe(0)
        ->and($this->context->bypass(fn (): int => Campaign::query()->count()))->toBe(3);

    $business = $this->context->bypass(fn (): Business => Business::factory()->create());
    $campaign = $this->context->bypass(fn (): Campaign => Campaign::factory()->for($business)->create());
    $this->context->bypass(fn () => CampaignSend::factory()->create(['campaign_id' => $campaign->id]));
    DB::table('businesses')->where('id', $business->id)->delete();

    expect(DB::table('campaign_sends')->where('business_id', $business->id)->count())->toBe(0);
});
