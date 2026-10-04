<?php

declare(strict_types=1);

use App\Actions\Account\MustHandOverBusiness;
use App\Actions\Tenancy\ArchiveBusiness;
use App\Actions\Tenancy\ArchiveLocation;
use App\Actions\Tenancy\ArchiveOrganization;
use App\Actions\Tenancy\ResolveTenant;
use App\Actions\Tenancy\RestoreArchived;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardBusiness;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\Reward;
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
            'a stamper moved there' => $this->context->bypass(function (): void {
                $otherLocation = Location::factory()->create(['business_id' => $this->tenants->a1->id]);
                Stamper::factory()->create(['business_id' => $this->tenants->a1->id, 'location_id' => $otherLocation->id])
                    ->update(['location_id' => $this->a1Location->id]);
            }),
            'a stamp' => $this->tenants->stamp($this->customer, $this->tenants->a1),
        };
    })->throws(LogicException::class, 'archived')->with(['a stamper', 'a stamper moved there', 'a stamp']);

    it('still takes a correction where the stamps were given, once archived', function (string $archived): void {
        $this->context->bypass(fn () => $archived === 'the location'
            ? app(ArchiveLocation::class)->handle($this->tenants->locationOf($this->tenants->a2))
            : app(ArchiveBusiness::class)->handle($this->tenants->a2));

        $correction = $this->tenants->stamp($this->customer, $this->tenants->a2, ['source' => StampSource::Correction, 'qty' => -1, 'reason' => 'Stamps given by mistake']);

        expect($correction->exists)->toBeTrue();
    })->with(['the location', 'the business']);

    it('takes no stamp but a correction once archived', function (): void {
        $this->context->bypass(fn () => app(ArchiveLocation::class)->handle($this->tenants->locationOf($this->tenants->a2)));

        $this->tenants->stamp($this->customer, $this->tenants->a2, ['source' => StampSource::Manual, 'qty' => 1, 'reason' => 'Card forgotten']);
    })->throws(LogicException::class, 'archived');

    it('keeps the date a location was first archived', function (): void {
        $this->context->bypass(fn () => app(ArchiveLocation::class)->handle($this->a1Location));
        $archivedAt = $this->a1Location->archived_at;

        $this->travel(1)->hours();
        $this->context->bypass(fn () => app(ArchiveLocation::class)->handle($this->a1Location));

        expect($this->a1Location->archived_at?->toIso8601String())->toBe($archivedAt?->toIso8601String());
    });

    it('leaves archiving and restoring to the Actions and the platform admin', function (string $how): void {
        if ($how === 'restore a location directly') {
            $this->context->bypass(fn () => app(ArchiveLocation::class)->handle($this->a1Location));
        }

        $this->context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        match ($how) {
            'archive a location directly' => $this->a1Location->forceFill(['archived_at' => now()])->save(),
            'restore a location directly' => $this->a1Location->forceFill(['archived_at' => null])->save(),
            'archive locations in bulk' => Location::query()->update(['archived_at' => now()]),
            'archive the business directly' => $this->tenants->a1->forceFill(['archived_at' => now()])->save(),
            'archive the organization directly' => $this->tenants->orgA->forceFill(['archived_at' => now()])->save(),
            'archive organizations in bulk' => Organization::query()->update(['archived_at' => now()]),
        };
    })->throws(LogicException::class, 'archiv')->with([
        'archive a location directly',
        'restore a location directly',
        'archive locations in bulk',
        'archive the business directly',
        'archive the organization directly',
        'archive organizations in bulk',
    ]);
});

describe('businesses', function (): void {
    it('lets franchise HQ archive a franchisee, closing it and keeping its history and cards', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);
        $events = $this->context->bypass(fn (): int => StampEvent::query()->count());

        app(ArchiveBusiness::class)->handle($this->tenants->a2);

        $this->context->bypass(function () use ($events): void {
            expect($this->tenants->a2->fresh()?->archived_at)->not->toBeNull()
                ->and($this->tenants->locationOf($this->tenants->a2)->archived_at)->not->toBeNull()
                ->and($this->a2Stamper->fresh()?->unassigned_at)->not->toBeNull()
                ->and(CardBusiness::query()->where('business_id', $this->tenants->a2->id)->exists())->toBeTrue()
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

        expect($this->context->bypass(fn (): bool => $this->tenants->b1->fresh()?->archived_at !== null))->toBeTrue();
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

    it('opens nothing new at an archived business, even in bypass() and while its location is open', function (string $what): void {
        $this->context->bypass(fn () => $this->tenants->a2->forceFill(['archived_at' => now()])->save());
        $a2Location = $this->tenants->locationOf($this->tenants->a2);

        match ($what) {
            'a stamp' => $this->tenants->stamp($this->customer, $this->tenants->a2),
            'a card' => $this->context->bypass(fn () => LoyaltyCard::factory()->for($this->tenants->orgA)->create()->businesses()->attach($this->tenants->a2)),
            'a stamper' => $this->context->bypass(fn () => Stamper::factory()->create(['business_id' => $this->tenants->a2->id, 'location_id' => $a2Location->id])),
            'a location' => $this->context->bypass(fn () => Location::factory()->create(['business_id' => $this->tenants->a2->id])),
            'a redemption' => $this->context->bypass(fn () => $this->tenants->reward($this->customer)->forceFill([
                'status' => RewardStatus::Redeemed,
                'redeemed_at' => now(),
                'redeemed_business_id' => $this->tenants->a2->id,
                'redeemed_location_id' => $a2Location->id,
            ])->save()),
            'an imported redemption' => $this->context->bypass(fn () => Reward::factory()->for($this->customer, 'enrollment')->create([
                'status' => RewardStatus::Redeemed,
                'redeemed_at' => now(),
                'redeemed_business_id' => $this->tenants->a2->id,
                'redeemed_location_id' => $a2Location->id,
            ])),
            'a staff member' => $this->tenants->member(User::factory()->create(), $this->tenants->a2),
        };
    })->throws(LogicException::class, 'archived')->with(['a stamp', 'a card', 'a stamper', 'a location', 'a redemption', 'an imported redemption', 'a staff member']);

    it('keeps the status a business had through an archive and a restore', function (BusinessStatus $status): void {
        $this->context->bypass(function () use ($status): void {
            $this->tenants->a2->forceFill(['status' => $status])->save();
            app(ArchiveBusiness::class)->handle($this->tenants->a2);
            app(RestoreArchived::class)->handle($this->tenants->a2);
        });

        expect($this->tenants->a2->status)->toBe($status)
            ->and($this->tenants->a2->archived_at)->toBeNull();
    })->with([BusinessStatus::Suspended, BusinessStatus::Pending]);
});

describe('organizations', function (): void {
    it('archives an organization only through the platform admin', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        app(ArchiveOrganization::class)->handle($this->tenants->orgA);
    })->throws(LogicException::class);

    it('archives every business of the organization, and keeps its cards and history', function (): void {
        $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
        $events = $this->context->bypass(fn (): int => StampEvent::query()->count());

        $this->context->bypass(fn () => app(ArchiveOrganization::class)->handle($this->tenants->orgA));

        $this->context->bypass(function () use ($events): void {
            expect($this->tenants->orgA->fresh()?->archived_at)->not->toBeNull()
                ->and(Business::query()->where('organization_id', $this->tenants->orgA->id)->whereNull('archived_at')->exists())->toBeFalse()
                ->and($this->tenants->cardA->fresh()?->active)->toBeTrue()
                ->and(StampEvent::query()->count())->toBe($events);
        });

        app(ResolveTenant::class)->handle($hq);

        expect($this->context->organizationId())->toBeNull();
    });

    it('opens nothing new in an archived organization, even at a business not archived itself', function (string $what): void {
        $otherLocation = $this->context->bypass(fn (): Location => Location::factory()->create(['business_id' => $this->tenants->a1->id]));
        $this->context->bypass(function (): void {
            app(ArchiveLocation::class)->handle($this->a1Location);
            $this->tenants->orgA->forceFill(['archived_at' => now()])->save();
        });

        $this->context->bypass(fn () => match ($what) {
            'a business' => Business::factory()->for($this->tenants->orgA)->create(),
            'an enrollment' => $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA),
            'a stamper moved there' => $this->a1Stamper->update(['location_id' => $otherLocation->id]),
            'a restored location' => app(RestoreArchived::class)->handle($this->a1Location),
            'a card' => LoyaltyCard::factory()->for($this->tenants->orgA)->create(),
            'an org admin' => $this->tenants->admin(User::factory()->create(), $this->tenants->orgA),
        });
    })->throws(LogicException::class, 'archived')->with(['a business', 'an enrollment', 'a stamper moved there', 'a restored location', 'a card', 'an org admin']);

    it('closes the businesses of an archived organization, even one not archived itself', function (): void {
        $a1Owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
        $this->context->bypass(fn () => $this->tenants->orgA->forceFill(['archived_at' => now()])->save());

        app(ResolveTenant::class)->handle($a1Owner);

        expect($this->context->organizationId())->toBeNull()
            ->and(fn () => $this->tenants->stamp($this->customer, $this->tenants->a1))->toThrow(LogicException::class, 'archived');
    });
});

describe('accounts', function (): void {
    it('lets the owner or org admin of a closed business delete their account', function (string $who): void {
        $user = match ($who) {
            'the owner of an archived franchisee' => $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner),
            'the owner of a café in an archived organization' => $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner),
            'the org admin of an archived organization' => $this->tenants->admin(User::factory()->create(), $this->tenants->orgB),
        };
        $mustHandOver = app(MustHandOverBusiness::class)->handle($user);

        $this->context->bypass(fn () => $who === 'the owner of an archived franchisee'
            ? app(ArchiveBusiness::class)->handle($this->tenants->a2)
            : $this->tenants->orgB->forceFill(['archived_at' => now()])->save());

        expect($mustHandOver)->toBeTrue()
            ->and(app(MustHandOverBusiness::class)->handle($user))->toBeFalse();
    })->with(['the owner of an archived franchisee', 'the owner of a café in an archived organization', 'the org admin of an archived organization']);
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
                'a business' => $this->tenants->a2->fresh()?->archived_at === null,
                'a location' => $this->a1Location->fresh()?->archived_at === null,
                'an organization' => $this->tenants->orgB->fresh()?->archived_at === null,
            })->toBeTrue();
        });
    })->with(['a business', 'a location', 'an organization']);

    it('restores only what is archived', function (string $what): void {
        $this->context->bypass(fn () => app(RestoreArchived::class)->handle(match ($what) {
            'a business' => $this->tenants->a2,
            'a location' => $this->a1Location,
            'an organization' => $this->tenants->orgB,
        }));
    })->throws(LogicException::class, 'Only an archived')->with(['a business', 'a location', 'an organization']);

    it('restores top down: never inside an organization or business still archived', function (string $what): void {
        $this->context->bypass(function () use ($what): void {
            match ($what) {
                'a business of an archived organization' => [app(ArchiveOrganization::class)->handle($this->tenants->orgA), app(RestoreArchived::class)->handle($this->tenants->a2)],
                'a location of an archived business' => [app(ArchiveBusiness::class)->handle($this->tenants->a2), app(RestoreArchived::class)->handle($this->tenants->locationOf($this->tenants->a2))],
            };
        });
    })->throws(LogicException::class, 'first')->with(['a business of an archived organization', 'a location of an archived business']);

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
