<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\CustomerNote;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Notes on a business's customers (CHW-44, B5)
|--------------------------------------------------------------------------
|
| Site data: a franchisee sees its own notes, the org admin the
| organization's, never another organization's, and franchisee A1 never
| sees A2's notes, even on the same person. Written once, never edited.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->user = User::factory()->create();
    $note = fn (Business $business): CustomerNote => $this->context->bypass(fn (): CustomerNote => CustomerNote::query()->create([
        'business_id' => $business->id, 'user_id' => $this->user->id, 'body' => 'Prefers oat milk.',
    ]));
    $this->notes = ['a1' => $note($this->tenants->a1), 'a2' => $note($this->tenants->a2), 'b1' => $note($this->tenants->b1)];
});

it('shows a franchisee its own notes, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
    $expected = match ($tenant) {
        'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), ['a1']],
        'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), ['a2']],
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), ['b1']],
        'no tenant' => [null, []],
    };

    expect(CustomerNote::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->notes[$key]->id, $expected[1]));
})->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);

it('never lets a franchisee reach another franchisee\'s or organization\'s note', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => CustomerNote::query()->findOrFail($this->notes[$other]->id))->toThrow(ModelNotFoundException::class);
})->with(['a2', 'b1']);

it('writes a note only for the business worked in', function (): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(CustomerNote::query()->create(['user_id' => $this->user->id, 'body' => 'x'])->business_id)->toBe($this->tenants->a1->id)
        ->and(fn () => CustomerNote::query()->create(['business_id' => $this->tenants->a2->id, 'user_id' => $this->user->id, 'body' => 'x']))
        ->toThrow(LogicException::class, 'another business');
});

it('never edits a note, even in bypass', function (): void {
    expect(fn () => $this->context->bypass(fn () => $this->notes['a1']->update(['body' => 'Changed'])))
        ->toThrow(LogicException::class, 'written once');
});
