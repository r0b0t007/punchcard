<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\DocumentStatus;
use App\Models\BusinessDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Business verification documents (CHW-145)
|--------------------------------------------------------------------------
|
| Site data: a franchisee reads its own, the org admin the organization's,
| never another organization's, and franchisee A1 never A2's. Every write is
| an Action's, in bypass(): no tenant creates, changes or deletes one.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->documents = $this->context->bypass(fn (): array => [
        'a1' => BusinessDocument::factory()->for($this->tenants->a1)->create(),
        'a2' => BusinessDocument::factory()->for($this->tenants->a2)->create(),
        'b1' => BusinessDocument::factory()->for($this->tenants->b1)->create(),
    ]);
});

it('shows a franchisee its own documents, the org admin the organization\'s, never another organization\'s', function (string $tenant): void {
    $expected = match ($tenant) {
        'franchisee A1' => [$this->context->set($this->tenants->orgA, $this->tenants->a1), ['a1']],
        'franchisee A2' => [$this->context->set($this->tenants->orgA, $this->tenants->a2), ['a2']],
        'org admin of A' => [$this->context->set($this->tenants->orgA, orgAdmin: true), ['a1', 'a2']],
        'business B1' => [$this->context->set($this->tenants->orgB, $this->tenants->b1), ['b1']],
        'no tenant' => [null, []],
    };

    expect(BusinessDocument::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (string $key): int => $this->documents[$key]->id, $expected[1]));
})->with(['franchisee A1', 'franchisee A2', 'org admin of A', 'business B1', 'no tenant']);

it('never finds another franchisee\'s or organization\'s document', function (string $other): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

    expect(fn () => BusinessDocument::query()->findOrFail($this->documents[$other]->id))->toThrow(ModelNotFoundException::class);
})->with(['a2', 'b1']);

it('takes no write from a tenant, even its own business\'s owner', function (string $write): void {
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);
    $own = BusinessDocument::query()->findOrFail($this->documents['a1']->id);

    match ($write) {
        'create' => BusinessDocument::factory()->for($this->tenants->a1)->create(),
        'decide' => $own->forceFill(['status' => DocumentStatus::Accepted, 'decided_at' => now()])->save(),
        'bulk' => BusinessDocument::query()->update(['status' => DocumentStatus::Accepted]),
        'delete' => $own->delete(),
        'bulk delete' => BusinessDocument::query()->delete(),
    };
})->with(['create', 'decide', 'bulk', 'delete', 'bulk delete'])->throws(LogicException::class, 'TenantContext::bypass()');

it('never shows where the file is', function (): void {
    expect($this->documents['a1']->toArray())->not->toHaveKey('path');
});
