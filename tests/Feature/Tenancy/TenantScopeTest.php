<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\OrganizationType;
use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Tenant isolation (ADR 0006)
|--------------------------------------------------------------------------
|
| Two boundaries: organization A vs B, and franchisee A1 vs A2 inside A.
| Without a tenant, scoped reads return nothing and writes throw (fail
| closed); code that must span tenants uses TenantContext::bypass().
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
});

describe('reads', function (): void {
    it('returns no tenant rows without a tenant context', function (): void {
        expect(Location::query()->count())->toBe(0)
            ->and(Business::query()->count())->toBe(0)
            ->and(Organization::query()->count())->toBe(0);
    });

    it('returns every row inside an explicit bypass, and only there', function (): void {
        expect($this->context->bypass(fn (): int => Location::query()->count()))->toBe(3)
            ->and($this->context->bypass(fn (): int => Organization::query()->count()))->toBe(2)
            ->and(Location::query()->count())->toBe(0);
    });

    it('limits an owner or staff member to their own business', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        expect(Location::query()->pluck('name')->all())->toBe(['A1 site'])
            ->and(Business::query()->pluck('name')->all())->toBe(['A1'])
            ->and($this->tenants->orgA->businesses()->pluck('name')->all())->toBe(['A1'])
            ->and(Location::query()->find($this->tenants->locationOf($this->tenants->a2)->id))->toBeNull()
            ->and(Business::query()->find($this->tenants->b1->id))->toBeNull();
    });

    it('lets an org admin see every business in the organization, and nothing outside it', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect(Location::query()->orderBy('name')->pluck('name')->all())->toBe(['A1 site', 'A2 site'])
            ->and(Business::query()->orderBy('name')->pluck('name')->all())->toBe(['A1', 'A2'])
            ->and(Location::query()->find($this->tenants->locationOf($this->tenants->b1)->id))->toBeNull();
    });

    it('never shows another organization', function (?string $business): void {
        $this->context->set($this->tenants->orgA, $business === null ? null : $this->tenants->{$business}, orgAdmin: $business === null);

        expect(Organization::query()->pluck('id')->all())->toBe([$this->tenants->orgA->id])
            ->and(Organization::query()->find($this->tenants->orgB->id))->toBeNull();
    })->with(['org admin' => [null], 'franchisee A1' => ['a1']]);

    it('ends the bypass even when the callback throws', function (): void {
        try {
            $this->context->bypass(fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        expect($this->context->isBypassed())->toBeFalse()
            ->and(Location::query()->count())->toBe(0);
    });
});

describe('writes', function (): void {
    it('refuses tenant writes without a tenant context', function (string $model): void {
        match ($model) {
            'location' => Location::query()->create(['name' => 'X', 'business_id' => $this->tenants->a1->id]),
            'business' => Business::query()->create(['name' => 'X', 'slug' => 'x', 'organization_id' => $this->tenants->orgA->id]),
            'organization' => Organization::query()->create(['name' => 'X', 'slug' => 'x']),
        };
    })->throws(LogicException::class)->with(['location', 'business', 'organization']);

    it('fills the tenant ids from the context when creating site data', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        $location = Location::query()->create(['name' => 'Terrace']);

        expect($location->business_id)->toBe($this->tenants->a1->id)
            ->and($location->organization_id)->toBe($this->tenants->orgA->id);
    });

    it('refuses to write site data for another tenant', function (string $business): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        Location::query()->create(['name' => 'Elsewhere', 'business_id' => $this->tenants->{$business}->id]);
    })->throws(LogicException::class)->with([
        'franchisee A2 in the same organization' => ['a2'],
        'business B1 in another organization' => ['b1'],
    ]);

    it('refuses to create a business in another organization', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        Business::query()->create(['name' => 'Rogue', 'slug' => 'rogue', 'organization_id' => $this->tenants->orgB->id]);
    })->throws(LogicException::class);

    it('derives organization_id from the business and rejects a mismatch, even in bypass', function (): void {
        $location = $this->context->bypass(fn (): Location => Location::query()->create(['name' => 'Kiosk', 'business_id' => $this->tenants->a1->id]));

        expect($location->organization_id)->toBe($this->tenants->orgA->id);

        $this->context->bypass(fn (): Location => Location::query()->create([
            'name' => 'Mismatch',
            'business_id' => $this->tenants->a1->id,
            'organization_id' => $this->tenants->orgB->id,
        ]));
    })->throws(LogicException::class);

    it('lets bypass() create a tenant elsewhere while a tenant is set', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        $business = $this->context->bypass(fn (): Business => Business::query()->create([
            'name' => 'New café', 'slug' => 'new-cafe', 'organization_id' => $this->tenants->orgB->id,
        ]));

        expect($business->organization_id)->toBe($this->tenants->orgB->id);
    });

    it('never moves a row to another tenant', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);
        $location = Location::query()->firstOrFail();
        $business = Business::query()->firstOrFail();

        match ($how) {
            'location save' => $location->forceFill(['business_id' => $this->tenants->a2->id])->save(),
            'business save' => $business->forceFill(['organization_id' => $this->tenants->orgB->id])->save(),
            'bulk update' => Location::query()->update(['business_id' => $this->tenants->a2->id]),
            'bulk update, qualified' => Location::query()->update(['locations.organization_id' => $this->tenants->orgB->id]),
            'increment with extra' => Location::query()->increment('id', 0, ['business_id' => $this->tenants->b1->id]),
        };
    })->throws(LogicException::class)->with(['location save', 'business save', 'bulk update', 'bulk update, qualified', 'increment with extra']);

    it('refuses raw inserts and upserts outside bypass()', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);
        $b1 = ['name' => 'X', 'business_id' => $this->tenants->b1->id, 'organization_id' => $this->tenants->orgB->id];

        match ($how) {
            'insert' => Location::query()->insert($b1),
            'insertGetId' => Location::query()->insertGetId($b1),
            'insertOrIgnore' => Location::query()->insertOrIgnore($b1),
            'updateOrInsert' => Location::query()->updateOrInsert(['name' => 'B1 site'], ['name' => 'taken']),
            'upsert' => Business::query()->upsert([['slug' => $this->tenants->b1->slug, 'name' => 'taken', 'organization_id' => $this->tenants->orgB->id]], ['slug'], ['name']),
        };
    })->throws(LogicException::class)->with(['insert', 'insertGetId', 'insertOrIgnore', 'updateOrInsert', 'upsert']);

    it('allows raw inserts inside bypass()', function (): void {
        $inserted = $this->context->bypass(fn (): bool => Location::query()->insert([
            'name' => 'Imported', 'business_id' => $this->tenants->b1->id, 'organization_id' => $this->tenants->orgB->id, 'timezone' => 'Africa/Casablanca',
        ]));

        expect($inserted)->toBeTrue();
    });

    it('still lets the database refuse a location whose organization is not its business\'s', function (): void {
        $this->context->bypass(fn (): bool => DB::table('locations')->insert([
            'name' => 'Inconsistent',
            'business_id' => $this->tenants->a1->id,
            'organization_id' => $this->tenants->orgB->id,
            'timezone' => 'Africa/Casablanca',
        ]));
    })->throws(QueryException::class);
});

describe('escape routes', function (): void {
    it('keeps forceDelete() inside the tenant', function (?string $business): void {
        if ($business !== null) {
            $this->context->set($this->tenants->orgA, $this->tenants->{$business});
        }

        Location::query()->forceDelete();

        $remaining = $this->context->bypass(fn (): array => Location::query()->orderBy('name')->pluck('name')->all());

        expect($remaining)->toBe($business === null ? ['A1 site', 'A2 site', 'B1 site'] : ['A2 site', 'B1 site']);
    })->with(['no tenant' => [null], 'franchisee A1' => ['a1']]);

    it('limits bulk updates and deletes to the tenant\'s own rows', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        Location::query()->update(['name' => 'renamed']);
        Location::query()->delete();

        $names = $this->context->bypass(fn (): array => Location::query()->orderBy('name')->pluck('name')->all());

        expect($names)->toBe(['A2 site', 'B1 site']);
    });

    it('needs bypass() for truncate() and updateFrom()', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        match ($how) {
            'truncate' => Location::query()->truncate(),
            'updateFrom' => Location::query()->updateFrom(['name' => 'x']),
        };
    })->throws(LogicException::class)->with(['truncate', 'updateFrom']);

    it('checks quiet and event-less creates like normal ones', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);
        $b1 = ['name' => 'Elsewhere', 'business_id' => $this->tenants->b1->id, 'organization_id' => $this->tenants->orgB->id];

        match ($how) {
            'createQuietly' => Location::query()->createQuietly($b1),
            'saveQuietly' => (new Location)->forceFill($b1)->saveQuietly(),
            'withoutEvents' => Model::withoutEvents(fn (): Location => Location::query()->create($b1)),
            'forceCreateQuietly' => Location::query()->forceCreateQuietly($b1),
            'business withoutEvents' => Model::withoutEvents(fn (): Business => Business::query()->create(['name' => 'X', 'slug' => 'x', 'organization_id' => $this->tenants->orgB->id])),
        };
    })->throws(LogicException::class)->with(['createQuietly', 'saveQuietly', 'withoutEvents', 'forceCreateQuietly', 'business withoutEvents']);

    it('checks creates without a tenant even when events are faked', function (string $model): void {
        Event::fake();

        match ($model) {
            'location' => Location::query()->createQuietly(['name' => 'X', 'business_id' => $this->tenants->a1->id]),
            'organization' => Organization::query()->createQuietly(['name' => 'X', 'slug' => 'x']),
        };
    })->throws(LogicException::class)->with(['location', 'organization']);

    it('still fills organization_id when events are faked', function (): void {
        Event::fake();

        $location = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create());

        expect($location->organization_id)->toBe($this->tenants->orgA->id);
    });

    it('lets an org admin write site data for any business of the organization only', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $location = Location::query()->create(['name' => 'A2 terrace', 'business_id' => $this->tenants->a2->id]);

        expect($location->organization_id)->toBe($this->tenants->orgA->id);

        Location::query()->create(['name' => 'B1 terrace', 'business_id' => $this->tenants->b1->id]);
    })->throws(LogicException::class);

    it('does not let a franchisee create a sibling business', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        $this->tenants->orgA->businesses()->create(['name' => 'A3', 'slug' => 'a3']);
    })->throws(LogicException::class);

    it('lets an org admin create a business in their organization', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $business = Business::query()->create(['name' => 'A3', 'slug' => 'a3']);

        expect($business->organization_id)->toBe($this->tenants->orgA->id);
    });

    it('needs bypass() for saveOrIgnore()', function (string $model): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        match ($model) {
            'location' => (new Location)->forceFill(['name' => 'X', 'business_id' => $this->tenants->b1->id])->saveOrIgnore(),
            'business' => (new Business)->forceFill(['name' => 'A3', 'slug' => 'a3'])->saveOrIgnore(),
            'organization' => (new Organization)->forceFill(['name' => 'X', 'slug' => 'x'])->saveOrIgnore(),
        };
    })->throws(LogicException::class)->with(['location', 'business', 'organization']);

    it('refuses saving or deleting a loaded model outside the tenant', function (string $how): void {
        $b1Location = $this->tenants->locationOf($this->tenants->b1);
        $a2Location = $this->tenants->locationOf($this->tenants->a2);
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        match ($how) {
            'update another organization' => $b1Location->update(['name' => 'pwned']),
            'delete a sibling franchisee' => $a2Location->delete(),
            'update another business' => $this->tenants->b1->update(['name' => 'pwned']),
        };
    })->throws(LogicException::class)->with(['update another organization', 'delete a sibling franchisee', 'update another business']);

    it('lets a loaded model in the tenant save and delete normally', function (): void {
        $location = $this->tenants->locationOf($this->tenants->a1);
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        $location->update(['name' => 'Renamed']);
        $location->delete();

        expect($this->context->bypass(fn (): ?Location => Location::query()->find($location->id)))->toBeNull();
    });

    it('does not let a franchisee change or delete the organization', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        try {
            match ($how) {
                'bulk rename' => Organization::query()->update(['name' => 'hijacked']),
                'bulk delete' => Organization::query()->delete(),
                'force delete' => Organization::query()->forceDelete(),
                'model save' => $this->tenants->orgA->update(['name' => 'hijacked']),
                'delete a business' => Business::query()->forceDelete(),
            };
        } finally {
            $this->context->clear();
        }

        expect($this->context->bypass(fn (): int => Location::query()->count()))->toBe(3);
    })->throws(LogicException::class)->with(['bulk rename', 'bulk delete', 'force delete', 'model save', 'delete a business']);

    it('keeps billing and verification fields for bypass()', function (string $how): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        match ($how) {
            'organization plan' => Organization::query()->update(['plan' => 'enterprise']),
            'organization white label' => $this->tenants->orgA->forceFill(['white_label' => true])->save(),
            'organization type' => Organization::query()->update(['TYPE' => 'chain']),
            'business status' => Business::query()->update(['status' => 'verified']),
            'business plan' => Business::query()->update(['businesses.plan' => 'pro']),
        };
    })->throws(LogicException::class)->with(['organization plan', 'organization white label', 'organization type', 'business status', 'business plan']);

    it('catches tenant columns whatever their case', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        Location::query()->update(['BUSINESS_ID' => $this->tenants->b1->id, 'Organization_Id' => $this->tenants->orgB->id]);
    })->throws(LogicException::class);

    it('lets the org admin rename the organization', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $this->tenants->orgA->update(['name' => 'Renamed network']);

        expect($this->tenants->orgA->refresh()->name)->toBe('Renamed network');
    });

    it('refuses to drop or replace the tenant scope outside bypass()', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        match ($how) {
            'withoutGlobalScopes' => Location::withoutGlobalScopes()->get(),
            'withoutGlobalScopesExcept' => Location::query()->withoutGlobalScopesExcept([])->get(),
            'withoutGlobalScope' => Location::query()->withoutGlobalScope(TenantScope::class)->get(),
            'replace with a no-op' => Location::query()->withGlobalScope(TenantScope::class, fn (): null => null)->get(),
            'relation rawUpdate' => $this->tenants->orgA->businesses()->rawUpdate(['name' => 'pwned']),
            'relation touch' => $this->tenants->orgA->businesses()->touch(),
        };
    })->throws(LogicException::class)->with([
        'withoutGlobalScopes', 'withoutGlobalScopesExcept', 'withoutGlobalScope', 'replace with a no-op', 'relation rawUpdate', 'relation touch',
    ]);

    it('lets bypass() drop the tenant scope', function (): void {
        $count = $this->context->bypass(fn (): int => Location::withoutGlobalScopes()->count());

        expect($count)->toBe(3);
    });

    it('guards builder touch() like update()', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        match ($how) {
            'tenant column' => Location::query()->touch('business_id'),
            'protected column' => Business::query()->touch(['status', 'plan']),
            'organization from a franchisee' => Organization::query()->touch('name'),
        };
    })->throws(LogicException::class)->with(['tenant column', 'protected column', 'organization from a franchisee']);

    it('refuses incrementing a loaded model outside the tenant', function (): void {
        $b1Location = $this->tenants->locationOf($this->tenants->b1);
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        $b1Location->increment('lat', 5);
    })->throws(LogicException::class);

    it('treats deleting a row that is already gone as done', function (): void {
        $location = $this->tenants->locationOf($this->tenants->a1);
        $again = $this->context->bypass(fn (): Location => Location::query()->findOrFail($location->id));
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        $location->delete();
        $again->delete();

        expect($this->context->bypass(fn (): int => Location::query()->count()))->toBe(2);
    });

    it('does not let an org admin create a verified or paid business', function (array $values): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        (new Business)->forceFill(['name' => 'A3', 'slug' => 'a3'] + $values)->save();
    })->throws(LogicException::class)->with([
        'verified' => [['status' => 'verified']],
        'with a plan' => [['plan' => 'enterprise']],
    ]);

    it('keeps tenant columns fixed in bypass() upserts and updateOrInsert', function (string $how): void {
        $a1Location = $this->tenants->locationOf($this->tenants->a1);
        $b1 = ['business_id' => $this->tenants->b1->id, 'organization_id' => $this->tenants->orgB->id];

        $this->context->bypass(fn (): mixed => match ($how) {
            'upsert' => Location::query()->upsert([['id' => $a1Location->id, 'name' => 'moved', 'timezone' => 'UTC'] + $b1], ['id']),
            'updateOrInsert' => Location::query()->updateOrInsert(['id' => $a1Location->id], $b1),
        });
    })->throws(LogicException::class)->with(['upsert', 'updateOrInsert']);

    it('lets the owner of an independent café edit its brand, but not a franchisee', function (): void {
        $independent = $this->context->bypass(fn (): array => [
            $organization = Organization::factory()->create(),
            Business::factory()->for($organization)->create(),
        ]);

        $this->context->set($independent[0], $independent[1], businessRole: BusinessRole::Owner);
        $independent[0]->update(['name' => 'Café rebranded']);

        expect($independent[0]->refresh()->name)->toBe('Café rebranded');

        $this->context->set($this->tenants->orgA, $this->tenants->a1);
        $this->tenants->orgA->update(['name' => 'hijacked']);
    })->throws(LogicException::class);

    it('refuses a different TenantScope in place of the registered one', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        Location::query()->withGlobalScope(TenantScope::class, new TenantScope)->get();
    })->throws(LogicException::class);

    it('checks tenant columns in a mixed upsert update list, even in bypass()', function (): void {
        $a1Location = $this->tenants->locationOf($this->tenants->a1);

        $this->context->bypass(fn (): int => Location::query()->upsert(
            [['id' => $a1Location->id, 'name' => 'x', 'business_id' => $this->tenants->b1->id, 'organization_id' => $this->tenants->orgB->id, 'timezone' => 'UTC']],
            ['id'],
            ['organization_id', 'updated_at' => now()],
        ));
    })->throws(LogicException::class);

    it('accepts a business whose organization id is a string', function (): void {
        $business = (new Business)->forceFill(['id' => $this->tenants->a1->id, 'organization_id' => (string) $this->tenants->orgA->id]);

        $this->context->set($this->tenants->orgA, $business);

        expect($this->context->businessId())->toBe($this->tenants->a1->id);
    });

    it('lets the business of a single-business chain edit its brand', function (): void {
        [$chain, $business] = $this->context->bypass(fn (): array => [
            $organization = Organization::factory()->create(['type' => OrganizationType::Chain]),
            Business::factory()->for($organization)->create(),
        ]);

        $this->context->set($chain, $business, businessRole: BusinessRole::Owner);
        $chain->update(['brand_color' => '#0F4C81']);

        expect($chain->refresh()->brand_color)->toBe('#0F4C81');
    });

    it('does not let the owner of a franchise\'s first franchisee change the shared brand', function (string $how): void {
        [$franchise, $business] = $this->context->bypass(fn (): array => [
            $organization = Organization::factory()->create(['type' => OrganizationType::Franchise]),
            Business::factory()->for($organization)->create(),
        ]);

        $this->context->set($franchise, $business, businessRole: BusinessRole::Owner);

        match ($how) {
            'model' => $franchise->update(['brand_color' => '#0F4C81']),
            'bulk' => Organization::query()->update(['brand_color' => '#0F4C81']),
        };
    })->throws(LogicException::class)->with(['model', 'bulk']);

    it('grants no org admin rights when set() is not told so', function (): void {
        $this->context->set($this->tenants->orgA);

        expect($this->context->isOrgAdmin())->toBeFalse()
            ->and($this->context->canManageMembers())->toBeFalse();

        Business::query()->create(['name' => 'A3', 'slug' => 'a3']);
    })->throws(LogicException::class, 'Only an org admin, working across the organization, can create a business.');

    it('keeps an independent café or chain to one business, even for its org admin', function (string $type, string $how): void {
        [$organization, $business] = $this->context->bypass(fn (): array => [
            $organization = Organization::factory()->create(['type' => $type]),
            Business::factory()->for($organization)->create(),
        ]);

        match ($how) {
            'create in the business' => [$this->context->set($organization, $business, orgAdmin: true, businessRole: BusinessRole::Owner), Business::query()->create(['name' => 'Second', 'slug' => 'second'])],
            'create across the organization' => [$this->context->set($organization, orgAdmin: true), Business::query()->create(['name' => 'Second', 'slug' => 'second'])],
            'delete from the business' => [$this->context->set($organization, $business, orgAdmin: true, businessRole: BusinessRole::Owner), $business->delete()],
            'delete across the organization' => [$this->context->set($organization, orgAdmin: true), $business->delete()],
        };
    })->throws(LogicException::class)->with(['independent', 'chain'])->with(['create in the business', 'create across the organization', 'delete from the business', 'delete across the organization']);

    it('lets franchise HQ remove a franchisee across the organization, but not from inside a site', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);
        $this->tenants->a2->delete();

        expect($this->context->bypass(fn (): bool => Business::query()->whereKey($this->tenants->a2->id)->exists()))->toBeFalse();

        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);
        $this->tenants->a1->delete();
    })->throws(LogicException::class, 'Only franchise HQ, working across the organization, can delete a business.');

    it('lets the owner change their business, but not staff', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);
        $this->tenants->a1->update(['name' => 'A1 renamed']);

        expect($this->tenants->a1->refresh()->name)->toBe('A1 renamed');

        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);
        $this->tenants->a1->update(['name' => 'renamed by staff']);
    })->throws(LogicException::class, 'Only the owner or an org admin can change the business.');

    it('needs bypass() for Organization::notSuspended(), whose answer depends on every business', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        Organization::query()->notSuspended()->get();
    })->throws(LogicException::class, 'use it inside TenantContext::bypass()');
});
