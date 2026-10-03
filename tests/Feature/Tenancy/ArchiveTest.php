<?php

declare(strict_types=1);

use App\Actions\Tenancy\ArchiveBusiness;
use App\Actions\Tenancy\ArchiveLocation;
use App\Actions\Tenancy\ArchiveOrganization;
use App\Actions\Tenancy\ResolveTenant;
use App\Actions\Tenancy\RestoreArchived;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\CardBusiness;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Archiving (CHW-139)
|--------------------------------------------------------------------------
|
| The stamp ledger is permanent, so a location, business or organization
| with history is closed by archiving it, never deleted: it stops operating
| (no tenant, no stampers, no stamps, no card participation) and its history
| stays true. Who may archive mirrors who may delete; only the platform
| admin (bypass()) restores.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->a1Stamper = $this->tenants->stamper($this->tenants->a1);
    $this->a2Stamper = $this->tenants->stamper($this->tenants->a2);
    $this->a1Location = $this->tenants->locationOf($this->tenants->a1);
    $this->customer = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);
    $this->tenants->stamp($this->customer, $this->tenants->a2);
});

describe('locations', function (): void {
    it('lets the owner or an org admin archive a location, and ends its stampers', function (string $who): void {
        match ($who) {
            'the owner' => $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner),
            'HQ' => $this->context->set($this->tenants->orgA, orgAdmin: true),
            'the platform admin' => null,
        };

        $who === 'the platform admin'
            ? $this->context->bypass(fn () => app(ArchiveLocation::class)->handle($this->a1Location))
            : app(ArchiveLocation::class)->handle($this->a1Location);

        $this->context->bypass(function (): void {
            expect($this->a1Location->fresh()?->archived_at)->not->toBeNull()
                ->and($this->a1Stamper->fresh()?->unassigned_at)->not->toBeNull();
        });
    })->with(['the owner', 'HQ', 'the platform admin']);

    it('refuses archiving a location for staff, a sibling franchisee or another organization', function (string $who): void {
        match ($who) {
            'staff' => $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff),
            'the sibling franchisee' => $this->context->set($this->tenants->orgA, $this->tenants->a2, businessRole: BusinessRole::Owner),
            'another organization' => $this->context->set($this->tenants->orgB, orgAdmin: true),
        };

        app(ArchiveLocation::class)->handle($this->a1Location);
    })->throws(LogicException::class)->with(['staff', 'the sibling franchisee', 'another organization']);

    it('frees the tags of an archived location for another assignment', function (): void {
        $this->context->bypass(function (): void {
            app(ArchiveLocation::class)->handle($this->a1Location);

            Stamper::factory()->create([
                'business_id' => $this->tenants->a2->id,
                'location_id' => $this->tenants->locationOf($this->tenants->a2)->id,
                'nfc_tag_id' => $this->a1Stamper->nfc_tag_id,
            ]);

            expect(Stamper::query()->current()->where('nfc_tag_id', $this->a1Stamper->nfc_tag_id)->value('business_id'))->toBe($this->tenants->a2->id);
        });
    });

    it('never puts a stamper or a stamp at an archived location, even in bypass()', function (string $what): void {
        $this->context->bypass(fn () => app(ArchiveLocation::class)->handle($this->a1Location));

        match ($what) {
            'a stamper' => $this->context->bypass(fn () => Stamper::factory()->create(['business_id' => $this->tenants->a1->id, 'location_id' => $this->a1Location->id])),
            'a stamp' => $this->tenants->stamp($this->customer, $this->tenants->a1),
        };
    })->throws(LogicException::class, 'archived')->with(['a stamper', 'a stamp']);

    it('leaves archiving and restoring a location to the Actions and the platform admin', function (string $how): void {
        if ($how === 'restore it directly') {
            $this->context->bypass(fn () => app(ArchiveLocation::class)->handle($this->a1Location));
        }

        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        $this->a1Location->forceFill(['archived_at' => $how === 'archive it directly' ? now() : null])->save();
    })->throws(LogicException::class, 'archived')->with(['archive it directly', 'restore it directly']);
});

describe('businesses', function (): void {
    it('lets franchise HQ archive a franchisee, closing it and keeping its history', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);
        $events = $this->context->bypass(fn (): int => StampEvent::query()->count());

        app(ArchiveBusiness::class)->handle($this->tenants->a2);

        $this->context->bypass(function () use ($events): void {
            expect($this->tenants->a2->fresh()?->status)->toBe(BusinessStatus::Archived)
                ->and($this->tenants->locationOf($this->tenants->a2)->archived_at)->not->toBeNull()
                ->and($this->a2Stamper->fresh()?->unassigned_at)->not->toBeNull()
                ->and(CardBusiness::query()->where('business_id', $this->tenants->a2->id)->exists())->toBeFalse()
                ->and(StampEvent::query()->count())->toBe($events)
                ->and(CardEnrollment::query()->whereKey($this->customer->id)->exists())->toBeTrue();
        });
    });

    it('refuses archiving a business for its franchisee, an independent owner, HQ inside a site, or another organization', function (string $who): void {
        [$business] = match ($who) {
            'the franchisee itself' => [$this->tenants->a2, $this->context->set($this->tenants->orgA, $this->tenants->a2, businessRole: BusinessRole::Owner)],
            'HQ from inside a site' => [$this->tenants->a2, $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner)],
            'an independent café\'s owner' => [$this->tenants->b1, $this->context->set($this->tenants->orgB, $this->tenants->b1, orgAdmin: true, businessRole: BusinessRole::Owner)],
            'another organization' => [$this->tenants->a2, $this->context->set($this->tenants->orgB, orgAdmin: true)],
        };

        app(ArchiveBusiness::class)->handle($business);
    })->throws(LogicException::class)->with(['the franchisee itself', 'HQ from inside a site', 'an independent café\'s owner', 'another organization']);

    it('lets the platform admin archive an independent café', function (): void {
        $this->context->bypass(fn () => app(ArchiveBusiness::class)->handle($this->tenants->b1));

        expect($this->context->bypass(fn (): ?BusinessStatus => $this->tenants->b1->fresh()?->status))->toBe(BusinessStatus::Archived);
    });

    it('gives an archived business\'s members no tenant, and keeps the rest of the franchise working', function (): void {
        $a2Owner = $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner);
        $a1Owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
        $this->context->bypass(fn () => app(ArchiveBusiness::class)->handle($this->tenants->a2));

        app(ResolveTenant::class)->handle($a2Owner);
        $a2Tenant = $this->context->organizationId();

        app(ResolveTenant::class)->handle($a1Owner);

        expect($a2Tenant)->toBeNull()
            ->and($this->context->businessId())->toBe($this->tenants->a1->id);

        $this->tenants->stamp($this->customer, $this->tenants->a1);
    });

    it('never stamps at or attaches a card to an archived business, even in bypass()', function (string $what): void {
        $this->context->bypass(fn () => $this->tenants->a2->forceFill(['status' => BusinessStatus::Archived])->save());

        match ($what) {
            'a stamp' => $this->tenants->stamp($this->customer, $this->tenants->a2),
            'a card' => $this->context->bypass(fn () => LoyaltyCard::factory()->for($this->tenants->orgA)->create()->businesses()->attach($this->tenants->a2)),
        };
    })->throws(LogicException::class, 'archived')->with(['a stamp', 'a card']);
});

describe('organizations', function (): void {
    it('archives an organization only through the platform admin', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        app(ArchiveOrganization::class)->handle($this->tenants->orgA);
    })->throws(LogicException::class);

    it('archives every business and card of the organization, and keeps its history', function (): void {
        $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
        $events = $this->context->bypass(fn (): int => StampEvent::query()->count());

        $this->context->bypass(fn () => app(ArchiveOrganization::class)->handle($this->tenants->orgA));

        $this->context->bypass(function () use ($events): void {
            expect($this->tenants->orgA->fresh()?->archived_at)->not->toBeNull()
                ->and(Business::query()->where('organization_id', $this->tenants->orgA->id)->pluck('status')->unique()->all())->toBe([BusinessStatus::Archived])
                ->and($this->tenants->cardA->fresh()?->active)->toBeFalse()
                ->and(StampEvent::query()->count())->toBe($events);
        });

        app(ResolveTenant::class)->handle($hq);

        expect($this->context->organizationId())->toBeNull();
    });
});

describe('restoring', function (): void {
    it('restores only through the platform admin', function (): void {
        $this->context->bypass(fn () => app(ArchiveBusiness::class)->handle($this->tenants->a2));
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        app(RestoreArchived::class)->handle($this->tenants->a2);
    })->throws(LogicException::class);

    it('lets the platform admin restore a business, a location or an organization', function (string $what): void {
        $this->context->bypass(function () use ($what): void {
            match ($what) {
                'a business' => [app(ArchiveBusiness::class)->handle($this->tenants->a2), app(RestoreArchived::class)->handle($this->tenants->a2)],
                'a location' => [app(ArchiveLocation::class)->handle($this->a1Location), app(RestoreArchived::class)->handle($this->a1Location)],
                'an organization' => [app(ArchiveOrganization::class)->handle($this->tenants->orgB), app(RestoreArchived::class)->handle($this->tenants->orgB)],
            };

            expect(match ($what) {
                'a business' => $this->tenants->a2->fresh()?->status === BusinessStatus::Verified,
                'a location' => $this->a1Location->fresh()?->archived_at === null,
                'an organization' => $this->tenants->orgB->fresh()?->archived_at === null,
            })->toBeTrue();
        });
    })->with(['a business', 'a location', 'an organization']);

    it('lets a restored business\'s members back in', function (): void {
        $a2Owner = $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner);
        $this->context->bypass(function (): void {
            app(ArchiveBusiness::class)->handle($this->tenants->a2);
            app(RestoreArchived::class)->handle($this->tenants->a2);
        });

        app(ResolveTenant::class)->handle($a2Owner);

        expect($this->context->businessId())->toBe($this->tenants->a2->id);
    });
});
