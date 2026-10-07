<?php

declare(strict_types=1);

use App\Actions\Stampers\MoveStamper;
use App\Actions\Stampers\RegisterStamper;
use App\Actions\Stampers\StamperRefused;
use App\Actions\Taps\ReceiveTap;
use App\Actions\Tenancy\ArchiveBusiness;
use App\Actions\Tenancy\ArchiveLocation;
use App\Enums\BusinessStatus;
use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\Tap;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Registering and moving stampers (CHW-138, docs/runbooks/stamper-keys.md)
|--------------------------------------------------------------------------
|
| The platform admin registers an NFC tag (platform state: uid, key version,
| counter) and assigns it to a location of a business (a stamper). A known tag
| that is free again is assigned as it is; moving a tag ends its assignment and
| adds one, keeping its counter and keys. The same Actions serve the artisan
| commands and the Filament screen; nothing here handles key material.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->register = app(RegisterStamper::class);
    $this->move = app(MoveStamper::class);
    $this->tagOf = fn (string $uid): ?NfcTag => $this->context->bypass(fn (): ?NfcTag => NfcTag::query()->where('uid', $uid)->first());
    $this->fresh = fn (Stamper $stamper): Stamper => $this->context->bypass(fn (): Stamper => $stamper->refresh());
    $this->tagsCount = fn (): int => $this->context->bypass(fn (): int => NfcTag::query()->count());
});

describe('registering', function (): void {
    it('registers a new tag at key version 1 and counter 0, at the business\'s only location', function (): void {
        $stamper = $this->register->handle('04a1b2c3d4e5f6', $this->tenants->a1, label: 'Counter');
        $tag = ($this->tagOf)('04A1B2C3D4E5F6');

        expect($tag)->not->toBeNull()
            ->and($tag->key_version)->toBe(1)
            ->and($tag->last_counter)->toBe(0)
            ->and($stamper->nfc_tag_id)->toBe($tag->id)
            ->and($stamper->business_id)->toBe($this->tenants->a1->id)
            ->and($stamper->organization_id)->toBe($this->tenants->orgA->id)
            ->and($stamper->location_id)->toBe($this->tenants->locationOf($this->tenants->a1)->id)
            ->and($stamper->label)->toBe('Counter')
            ->and(($this->fresh)($stamper)->status)->toBe(StamperStatus::Active)
            ->and($stamper->unassigned_at)->toBeNull();
    });

    it('shows a new stamper to its franchisee and the org admin, never to another franchisee or organization', function (): void {
        $stamper = $this->register->handle('04A1B2C3D4E5F6', $this->tenants->a1);

        $sees = function () use ($stamper): bool {
            $seen = Stamper::query()->whereKey($stamper->id)->exists();
            $this->context->clear();

            return $seen;
        };

        $this->context->set($this->tenants->orgA, $this->tenants->a1);
        expect($sees())->toBeTrue();
        $this->context->set($this->tenants->orgA, orgAdmin: true);
        expect($sees())->toBeTrue();
        $this->context->set($this->tenants->orgA, $this->tenants->a2);
        expect($sees())->toBeFalse();
        $this->context->set($this->tenants->orgB, $this->tenants->b1);
        expect($sees())->toBeFalse();
    });

    it('reads a uid as a reader prints it', function (string $typed): void {
        $this->register->handle($typed, $this->tenants->a1);

        expect(($this->tagOf)('04A1B2C3D4E5F6'))->not->toBeNull();
    })->with(['spaces' => '04 a1 b2 c3 d4 e5 f6', 'colons' => '04:A1:B2:C3:D4:E5:F6', 'padded' => "  04A1B2C3D4E5F6\n"]);

    it('refuses anything but a 7-byte NXP uid', function (string $typed, string $reason): void {
        expect(fn () => $this->register->handle($typed, $this->tenants->a1))->toThrow(StamperRefused::class, $reason)
            ->and(($this->tagsCount)())->toBe(0);
    })->with([
        'too short' => ['04A1B2C3D4E5', '14 hex digits'],
        'too long' => ['04A1B2C3D4E5F607', '14 hex digits'],
        'not hex' => ['04A1B2C3D4E5G6', '14 hex digits'],
        'not NXP' => ['05A1B2C3D4E5F6', 'start with 04'],
    ]);

    it('assigns a known tag that is free again, keeping its counter and key version', function (): void {
        $old = $this->tenants->stamper($this->tenants->a1);
        $tag = $this->context->bypass(function () use ($old): NfcTag {
            $old->forceFill(['unassigned_at' => now()])->save();
            $tag = $old->tag()->firstOrFail();
            NfcTag::query()->whereKey($tag->id)->update(['last_counter' => 7, 'key_version' => 2]);

            return $tag->refresh();
        });

        $stamper = $this->register->handle($tag->uid, $this->tenants->a2);

        expect($stamper->nfc_tag_id)->toBe($tag->id)
            ->and($stamper->business_id)->toBe($this->tenants->a2->id)
            ->and(($this->tagOf)($tag->uid)->last_counter)->toBe(7)
            ->and(($this->tagOf)($tag->uid)->key_version)->toBe(2)
            ->and(($this->tagsCount)())->toBe(1);
    });

    it('refuses a tag already assigned, or retired', function (string $state, string $reason): void {
        $tag = match ($state) {
            'assigned' => $this->context->bypass(fn (): NfcTag => $this->tenants->stamper($this->tenants->a1)->tag()->firstOrFail()),
            'retired' => $this->context->bypass(fn (): NfcTag => NfcTag::factory()->create(['retired_at' => now()])),
        };

        expect(fn () => $this->register->handle($tag->uid, $this->tenants->a2))->toThrow(StamperRefused::class, $reason)
            ->and($this->context->bypass(fn (): int => Stamper::query()->where('business_id', $this->tenants->a2->id)->count()))->toBe(0);
    })->with([
        'assigned' => ['assigned', 'move it instead'],
        'retired' => ['retired', 'register a new tag'],
    ]);

    it('lets a pending business set up, never a suspended or archived one', function (string $state, ?string $reason): void {
        $business = $this->tenants->a1;
        match ($state) {
            'pending', 'suspended' => $this->context->bypass(fn () => $business->forceFill(['status' => BusinessStatus::from($state)])->save()),
            'archived' => $this->context->bypass(fn () => app(ArchiveBusiness::class)->handle($business)),
        };

        $register = fn () => $this->register->handle('04A1B2C3D4E5F6', $business);

        $reason === null
            ? expect($register()->business_id)->toBe($business->id)
            : expect($register)->toThrow(StamperRefused::class, $reason);
    })->with([
        'pending' => ['pending', null],
        'suspended' => ['suspended', 'suspended'],
        'archived' => ['archived', 'archived'],
    ]);

    it('refuses a location of another business, or an archived one', function (string $state, string $reason): void {
        $location = match ($state) {
            'elsewhere' => $this->tenants->locationOf($this->tenants->a2),
            'archived' => $this->context->bypass(function (): Location {
                $location = Location::factory()->for($this->tenants->a1)->create(['name' => 'Old kiosk']);
                app(ArchiveLocation::class)->handle($location);

                return $location->refresh();
            }),
        };

        expect(fn () => $this->register->handle('04A1B2C3D4E5F6', $this->tenants->a1, $location))->toThrow(StamperRefused::class, $reason)
            ->and(($this->tagsCount)())->toBe(0);
    })->with([
        'elsewhere' => ['elsewhere', 'not a location of A1'],
        'archived' => ['archived', 'archived'],
    ]);

    it('takes a label up to 255 characters, and a location that is gone is a refusal', function (): void {
        $gone = (new Location)->forceFill(['id' => 999999999, 'business_id' => $this->tenants->a1->id]);

        expect(fn () => $this->register->handle('04A1B2C3D4E5F6', $this->tenants->a1, label: str_repeat('é', 256)))->toThrow(StamperRefused::class, 'at most 255')
            ->and(fn () => $this->register->handle('04A1B2C3D4E5F6', $this->tenants->a1, $gone))->toThrow(StamperRefused::class, 'no longer exists')
            ->and($this->register->handle('04A1B2C3D4E5F6', $this->tenants->a1, label: str_repeat('é', 255))->label)->toBe(str_repeat('é', 255));
    });

    it('counts only open locations when it picks the business\'s only one', function (): void {
        $this->context->bypass(fn () => app(ArchiveLocation::class)->handle(Location::factory()->for($this->tenants->a1)->create(['name' => 'Old kiosk'])));

        expect($this->register->handle('04A1B2C3D4E5F6', $this->tenants->a1)->location_id)->toBe($this->tenants->locationOf($this->tenants->a1)->id);
    });

    it('needs the location named when the business has several open ones, and refuses one with none', function (): void {
        $terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['name' => 'Terrace']));
        $bare = $this->context->bypass(fn (): Business => Business::factory()->for($this->tenants->orgA)->create(['name' => 'Bare']));

        expect(fn () => $this->register->handle('04A1B2C3D4E5F6', $this->tenants->a1))->toThrow(StamperRefused::class, 'A1 site')
            ->and(fn () => $this->register->handle('04A1B2C3D4E5F6', $this->tenants->a1))->toThrow(StamperRefused::class, 'Terrace')
            ->and(fn () => $this->register->handle('04A1B2C3D4E5F6', $bare))->toThrow(StamperRefused::class, 'no open location')
            ->and($this->register->handle('04A1B2C3D4E5F6', $this->tenants->a1, $terrace)->location_id)->toBe($terrace->id);
    });
});

describe('moving', function (): void {
    it('ends the assignment and adds one, keeping the tag\'s counter and key version', function (): void {
        $old = $this->tenants->stamper($this->tenants->a1);
        $tag = $this->context->bypass(function () use ($old): NfcTag {
            $tag = $old->tag()->firstOrFail();
            NfcTag::query()->whereKey($tag->id)->update(['last_counter' => 9, 'key_version' => 2]);

            return $tag->refresh();
        });

        $moved = $this->move->handle($tag->uid, $this->tenants->a2, label: 'Bar');

        expect($moved->id)->not->toBe($old->id)
            ->and($moved->nfc_tag_id)->toBe($tag->id)
            ->and($moved->business_id)->toBe($this->tenants->a2->id)
            ->and($moved->label)->toBe('Bar')
            ->and(($this->fresh)($old)->unassigned_at)->not->toBeNull()
            ->and(($this->tagOf)($tag->uid)->last_counter)->toBe(9)
            ->and(($this->tagOf)($tag->uid)->key_version)->toBe(2);
    });

    it('keeps the stamper\'s label and, within the business, its pause', function (): void {
        $old = $this->tenants->stamper($this->tenants->a1);
        $terrace = $this->context->bypass(function () use ($old): Location {
            $old->forceFill(['label' => 'Counter', 'status' => StamperStatus::Disabled])->save();

            return Location::factory()->for($this->tenants->a1)->create(['name' => 'Terrace']);
        });
        $uid = $this->context->bypass(fn (): string => $old->tag()->firstOrFail()->uid);

        $moved = ($this->fresh)($this->move->handle($uid, $this->tenants->a1, $terrace));

        expect($moved->label)->toBe('Counter')
            ->and($moved->status)->toBe(StamperStatus::Disabled)
            ->and($moved->location_id)->toBe($terrace->id);
    });

    it('starts the new stamper active at another business, whatever the old business did with its own', function (): void {
        $old = $this->tenants->stamper($this->tenants->a1);
        $this->context->bypass(fn () => $old->forceFill(['status' => StamperStatus::Disabled])->save());
        $uid = $this->context->bypass(fn (): string => $old->tag()->firstOrFail()->uid);

        expect(($this->fresh)($this->move->handle($uid, $this->tenants->a2))->status)->toBe(StamperStatus::Active);
    });

    it('never moves a tag to a suspended or archived business, and leaves it where it was', function (string $state): void {
        $old = $this->tenants->stamper($this->tenants->a1);
        $uid = $this->context->bypass(fn (): string => $old->tag()->firstOrFail()->uid);
        $this->context->bypass(fn () => $state === 'suspended'
            ? $this->tenants->a2->forceFill(['status' => BusinessStatus::Suspended])->save()
            : app(ArchiveBusiness::class)->handle($this->tenants->a2));

        expect(fn () => $this->move->handle($uid, $this->tenants->a2))->toThrow(StamperRefused::class, $state)
            ->and(($this->fresh)($old)->unassigned_at)->toBeNull();
    })->with(['suspended', 'archived']);

    it('refuses a move to where the tag already is, and a tag that is unknown, free or retired', function (string $state, string $reason): void {
        $uid = match ($state) {
            'same place' => $this->context->bypass(fn (): string => $this->tenants->stamper($this->tenants->a2)->tag()->firstOrFail()->uid),
            'unknown' => '04A1B2C3D4E5F6',
            'free' => $this->context->bypass(fn (): string => NfcTag::factory()->create()->uid),
            'retired' => $this->context->bypass(fn (): string => NfcTag::factory()->create(['retired_at' => now()])->uid),
        };

        expect(fn () => $this->move->handle($uid, $this->tenants->a2))->toThrow(StamperRefused::class, $reason);
    })->with([
        'same place' => ['same place', 'already at'],
        'unknown' => ['unknown', 'register it'],
        'free' => ['free', 'register it'],
        'retired' => ['retired', 'register a new tag'],
    ]);

    it('shows the moved stamper to its new franchisee and the org admin, never to the old holder or another organization', function (): void {
        $old = $this->tenants->stamper($this->tenants->a1);
        $uid = $this->context->bypass(fn (): string => $old->tag()->firstOrFail()->uid);
        $moved = $this->move->handle($uid, $this->tenants->a2);

        $current = function (): array {
            $ids = Stamper::query()->current()->pluck('id')->all();
            $this->context->clear();

            return $ids;
        };

        $this->context->set($this->tenants->orgA, $this->tenants->a1);
        expect($current())->not->toContain($moved->id);
        $this->context->set($this->tenants->orgA, $this->tenants->a2);
        expect($current())->toContain($moved->id);
        $this->context->set($this->tenants->orgA, orgAdmin: true);
        expect($current())->toContain($moved->id);
        $this->context->set($this->tenants->orgB, $this->tenants->b1);
        expect($current())->toBe([]);
    });
});

describe('the tap path', function (): void {
    beforeEach(function (): void {
        config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
        app()->forgetInstance(KeyDiversifier::class);
        $this->receive = function (string $uid, int $counter): Tap {
            $url = app(FakeTap::class)->build($uid, $counter, 1);

            return app(ReceiveTap::class)->handle($url['e'], $url['c'], null, '203.0.113.7', 'Test phone');
        };
    });

    it('keeps the old site\'s URLs replays after a move, and sends new taps to the new stamper', function (): void {
        $old = $this->tenants->stamper($this->tenants->a1);
        $uid = $this->context->bypass(fn (): string => $old->tag()->firstOrFail()->uid);
        $atA1 = ($this->receive)($uid, 5);

        $moved = $this->move->handle($uid, $this->tenants->a2);

        expect($atA1->stamper_id)->toBe($old->id)
            ->and(($this->receive)($uid, 5)->rejection)->toBe(TapRejection::Replay)
            ->and(($this->tagOf)($uid)->last_counter)->toBe(5)
            ->and(($this->receive)($uid, 6)->stamper_id)->toBe($moved->id);
    });

    it('locks the tag without blocking a stamp event\'s foreign key check on it', function (string $operation): void {
        $old = $this->tenants->stamper($this->tenants->a1);
        $uid = $this->context->bypass(fn (): string => $old->tag()->firstOrFail()->uid);

        if ($operation === 'register') {
            $this->context->bypass(fn () => $old->forceFill(['unassigned_at' => now()])->save());
        }

        DB::enableQueryLog();
        $operation === 'register' ? $this->register->handle($uid, $this->tenants->a2) : $this->move->handle($uid, $this->tenants->a2);

        expect(collect(DB::getQueryLog())->pluck('query')->contains(fn (string $sql): bool => str_contains($sql, 'from "nfc_tags"') && str_contains($sql, 'for no key update')))->toBeTrue();
    })->with(['register', 'move'])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Row locks compile on Postgres only');
});

describe('the commands', function (): void {
    beforeEach(function (): void {
        $this->master = '6F1D0C4E2B8A97355A0E1F3C7D2B8846';
        config(['punchcard.nfc.sun_master_key' => $this->master]);
    });

    it('registers by business slug or id and prints the assignment, nothing secret', function (): void {
        expect(Artisan::call('punchcard:stamper:register', ['uid' => '04a1b2c3d4e5f6', 'business' => $this->tenants->a1->slug, '--label' => 'Counter']))->toBe(0)
            ->and(Artisan::output())
            ->toContain('Registered tag 04A1B2C3D4E5F6 (key version 1)')
            ->toContain('at A1, A1 site (#'.$this->tenants->locationOf($this->tenants->a1)->id.')')
            ->not->toContain($this->master);

        $this->artisan('punchcard:stamper:register', ['uid' => '04A1B2C3D4E5F7', 'business' => (string) $this->tenants->a2->id])
            ->assertSuccessful();

        expect(($this->tagOf)('04A1B2C3D4E5F7'))->not->toBeNull();
    });

    it('finds a business by its slug before its id, so an all-digit slug still works', function (): void {
        $numbered = $this->context->bypass(fn (): Business => Business::factory()->for($this->tenants->orgB)->create(['name' => 'Café 2024', 'slug' => (string) $this->tenants->a2->id]));
        $this->context->bypass(fn () => Location::factory()->for($numbered)->create(['name' => 'Kiosk']));

        expect(Artisan::call('punchcard:stamper:register', ['uid' => '04A1B2C3D4E5F6', 'business' => (string) $this->tenants->a2->id]))->toBe(0)
            ->and(Artisan::output())->toContain('at Café 2024, Kiosk');
    });

    it('names the location by id when there are several', function (): void {
        $terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['name' => 'Terrace']));

        $this->artisan('punchcard:stamper:register', ['uid' => '04A1B2C3D4E5F6', 'business' => $this->tenants->a1->slug])
            ->expectsOutputToContain('Terrace (#'.$terrace->id.')')
            ->assertFailed();

        $this->artisan('punchcard:stamper:register', ['uid' => '04A1B2C3D4E5F6', 'business' => $this->tenants->a1->slug, '--location' => (string) $terrace->id])
            ->assertSuccessful();
    });

    it('fails on an unknown business or location, and on a refusal', function (string $uid, ?string $business, ?string $location, string $message): void {
        $arguments = ['uid' => $uid, 'business' => $business ?? $this->tenants->a1->slug] + ($location === null ? [] : ['--location' => $location]);

        $this->artisan('punchcard:stamper:register', $arguments)
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(($this->tagsCount)())->toBe(0);
    })->with([
        'unknown business' => ['04A1B2C3D4E5F6', 'nowhere', null, 'No business'],
        'unknown location' => ['04A1B2C3D4E5F6', null, '999999999', 'No location'],
        'bad uid' => ['XYZ', null, null, '14 hex digits'],
    ]);

    it('moves a tag', function (): void {
        $old = $this->tenants->stamper($this->tenants->a1);
        $uid = $this->context->bypass(fn (): string => $old->tag()->firstOrFail()->uid);

        expect(Artisan::call('punchcard:stamper:move', ['uid' => $uid, 'business' => $this->tenants->a2->slug]))->toBe(0)
            ->and(Artisan::output())
            ->toContain('Moved tag '.$uid)
            ->toContain('at A2, A2 site (#'.$this->tenants->locationOf($this->tenants->a2)->id.')');

        $this->artisan('punchcard:stamper:move', ['uid' => $uid, 'business' => $this->tenants->a2->slug])
            ->expectsOutputToContain('already at')
            ->assertFailed();
    });
});
