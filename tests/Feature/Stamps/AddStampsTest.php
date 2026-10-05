<?php

declare(strict_types=1);

use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Actions\Stamps\StampResult;
use App\Actions\Tenancy\ArchiveBusiness;
use App\Actions\Tenancy\ArchiveLocation;
use App\Enums\BusinessRole;
use App\Enums\CardMode;
use App\Enums\RewardStatus;
use App\Enums\StamperStatus;
use App\Enums\StampRejection;
use App\Enums\StampSource;
use App\Events\EnrollmentChanged;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| AddStamps (CHW-24)
|--------------------------------------------------------------------------
|
| The single entry point for every stamp: rules (card, site, stamper,
| cooldown per customer per card, daily cap per customer per business in the
| location's timezone), the append-only ledger, the progress cache, rewards
| (cyclic with carry-over, progressive tiers), idempotency and the
| EnrollmentChanged event after commit.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 10:00:00');

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->enrollment = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);
    $this->a1Staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->a2Staff = $this->tenants->member(User::factory()->create(), $this->tenants->a2);
    $this->a1Location = $this->tenants->locationOf($this->tenants->a1);
    $this->a2Location = $this->tenants->locationOf($this->tenants->a2);

    $this->cardModel = $this->tenants->cardA;
    $this->card = function (array $settings): void {
        $this->context->bypass(fn () => $this->cardModel->forceFill($settings)->save());
    };
    $this->qr = fn (int $qty = 1, ?string $key = null, ?Location $at = null, ?User $staff = null): StampRequest => StampRequest::qr(
        $at ?? $this->a1Location,
        $staff ?? $this->a1Staff,
        $key ?? (string) Str::uuid(),
        $qty,
    );
    $this->add = fn (StampRequest $request, ?CardEnrollment $enrollment = null): StampResult => $this->context->bypass(
        fn (): StampResult => app(AddStamps::class)->handle($enrollment ?? $this->enrollment, $request),
    );
    $this->rejection = function (Closure $stamp): ?StampRejection {
        try {
            $stamp();
        } catch (StampRejected $rejected) {
            return $rejected->rejection;
        }

        return null;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('cooldown, per customer per card', function (): void {
    it('refuses a second stamp inside the cooldown, and says when the next one is possible', function (): void {
        ($this->add)(($this->qr)());
        $this->travel(20)->minutes();
        $this->travel(-1)->seconds();

        try {
            ($this->add)(($this->qr)());
            $this->fail('The cooldown let a stamp through.');
        } catch (StampRejected $rejected) {
            expect($rejected->rejection)->toBe(StampRejection::Cooldown)
                ->and($rejected->availableAt?->toDateTimeString())->toBe('2026-10-05 10:20:00')
                ->and($rejected->cardStamps)->toBe(1)
                ->and($rejected->getPrevious())->toBeInstanceOf(StampRejected::class);
        }

        $this->travel(1)->seconds();

        expect(($this->add)(($this->qr)())->enrollment->lifetime_stamps)->toBe(2);
    });

    it('spans the businesses of a franchise card', function (): void {
        ($this->add)(($this->qr)());

        expect(($this->rejection)(fn () => ($this->add)(($this->qr)(at: $this->a2Location, staff: $this->a2Staff))))->toBe(StampRejection::Cooldown);
    });

    it('holds manual stamps to it, like scans', function (): void {
        ($this->add)(($this->qr)());

        expect(($this->rejection)(fn () => ($this->add)(StampRequest::manual($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Card forgotten', 1))))->toBe(StampRejection::Cooldown);
    });

    it('does not hold back corrections or system stamps', function (Closure $request): void {
        ($this->add)(($this->qr)(2));

        expect(($this->add)($request->call($this))->event->exists)->toBeTrue();
    })->with([
        'correction' => fn (): StampRequest => StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Double stamp', -1),
        'bonus' => fn (): StampRequest => StampRequest::system(StampSource::Bonus, $this->a1Location, (string) Str::uuid()),
    ]);
});

it('starts the cooldown with stamps that prove the customer was there', function (string $first, ?StampRejection $then): void {
    if ($first === 'correction') {
        ($this->add)(StampRequest::system(StampSource::Bonus, $this->a1Location, (string) Str::uuid(), 2));
    }

    ($this->add)(match ($first) {
        'manual' => StampRequest::manual($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Card forgotten', 1),
        'correction' => StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Double stamp', -1),
        'bonus' => StampRequest::system(StampSource::Bonus, $this->a1Location, (string) Str::uuid()),
    });

    expect(($this->rejection)(fn () => ($this->add)(($this->qr)())))->toBe($then);
})->with([
    'a manual stamp' => ['manual', StampRejection::Cooldown],
    'not a correction' => ['correction', null],
    'not a bonus' => ['bonus', null],
]);

describe('daily cap, per customer per business', function (): void {
    beforeEach(fn () => ($this->card)(['cooldown_min' => 0, 'daily_cap' => 5]));

    it('allows exactly the cap, then refuses', function (): void {
        ($this->add)(($this->qr)(3));
        ($this->add)(($this->qr)(2));

        expect(($this->rejection)(fn () => ($this->add)(($this->qr)())))->toBe(StampRejection::DailyCap);
    });

    it('refuses a stamp that would go over the cap, even if some room is left', function (): void {
        ($this->add)(($this->qr)(4));

        expect(($this->rejection)(fn () => ($this->add)(($this->qr)(2))))->toBe(StampRejection::DailyCap);
    });

    it('counts each business of the card separately', function (): void {
        ($this->add)(($this->qr)(5));

        expect(($this->add)(($this->qr)(at: $this->a2Location, staff: $this->a2Staff))->enrollment->lifetime_stamps)->toBe(6);
    });

    it('starts a new day at midnight where the stamp is given', function (): void {
        $this->context->bypass(fn () => $this->a1Location->forceFill(['timezone' => 'Asia/Tokyo'])->save());
        Carbon::setTestNow('2026-10-05 14:30:00');
        ($this->add)(($this->qr)(5));

        Carbon::setTestNow('2026-10-05 14:59:59');
        expect(($this->rejection)(fn () => ($this->add)(($this->qr)())))->toBe(StampRejection::DailyCap);

        Carbon::setTestNow('2026-10-05 15:00:00');
        expect(($this->add)(($this->qr)())->enrollment->lifetime_stamps)->toBe(6);
    });

    it('has no cap when the card sets none', function (): void {
        ($this->card)(['daily_cap' => null]);
        ($this->add)(($this->qr)(40));

        expect(($this->add)(($this->qr)(10))->enrollment->lifetime_stamps)->toBe(50);
    });

    it('sums taps, scans and manual stamps, leaves bonus stamps and corrections out, and writes nothing when it refuses', function (): void {
        ($this->add)(StampRequest::system(StampSource::Bonus, $this->a1Location, (string) Str::uuid(), 4));
        ($this->add)(StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Bonus given twice', -2));
        ($this->add)(StampRequest::nfc($this->tenants->stamper($this->tenants->a1), 1, 2));
        ($this->add)(StampRequest::manual($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Card forgotten', 1));
        ($this->add)(($this->qr)(2));
        $before = $this->context->bypass(fn (): array => [$this->enrollment->fresh()?->only(['current_stamps', 'lifetime_stamps', 'last_stamp_at']), StampEvent::query()->count(), Reward::query()->count()]);

        expect(($this->rejection)(fn () => ($this->add)(($this->qr)())))->toBe(StampRejection::DailyCap)
            ->and($this->context->bypass(fn (): array => [$this->enrollment->fresh()?->only(['current_stamps', 'lifetime_stamps', 'last_stamp_at']), StampEvent::query()->count(), Reward::query()->count()]))->toEqual($before);
    });

    it('refuses a manual stamp that does not fit', function (): void {
        ($this->add)(StampRequest::manual($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Birthday party', 3));

        expect(($this->rejection)(fn () => ($this->add)(StampRequest::manual($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Another round', 3))))->toBe(StampRejection::DailyCap)
            ->and(($this->add)(($this->qr)(2))->enrollment->lifetime_stamps)->toBe(5);
    });

    it('gives an armed tap the room left under the cap, then refuses', function (): void {
        $stamper = $this->tenants->stamper($this->tenants->a1);
        ($this->add)(($this->qr)(3));

        $armed = ($this->add)(StampRequest::nfc($stamper, 1, 4));

        expect($armed->event->qty)->toBe(2)
            ->and($armed->enrollment->lifetime_stamps)->toBe(5)
            ->and(($this->rejection)(fn () => ($this->add)(StampRequest::nfc($stamper, 2))))->toBe(StampRejection::DailyCap);
    });
});

describe('cyclic cards', function (): void {
    beforeEach(fn () => ($this->card)(['cooldown_min' => 0, 'daily_cap' => null, 'reward_text' => 'Free latte']));

    it('unlocks a reward when the card fills, and carries the extra stamps over', function (): void {
        ($this->add)(($this->qr)(9));
        $result = ($this->add)(($this->qr)(3));

        expect($result->enrollment->only(['current_stamps', 'lifetime_stamps', 'completed_count']))->toBe(['current_stamps' => 2, 'lifetime_stamps' => 12, 'completed_count' => 1])
            ->and($result->rewards)->toHaveCount(1)
            ->and($result->rewards[0]->only(['mode', 'milestone', 'reward_text', 'status', 'expires_at']))->toBe([
                'mode' => CardMode::Cyclic,
                'milestone' => 1,
                'reward_text' => 'Free latte',
                'status' => RewardStatus::Available,
                'expires_at' => null,
            ]);
    });

    it('empties the card when it fills exactly', function (): void {
        $result = ($this->add)(($this->qr)(10));

        expect($result->enrollment->current_stamps)->toBe(0)
            ->and($result->rewards)->toHaveCount(1);
    });

    it('numbers rewards after the ones already there, imported or not', function (): void {
        $this->tenants->reward($this->enrollment);

        expect(($this->add)(($this->qr)(10))->rewards[0]->milestone)->toBe(2);
    });

    it('never pays out on a correction that takes stamps back, even after the card was made shorter', function (): void {
        ($this->add)(($this->qr)(8));
        ($this->card)(['stamps_required' => 5]);

        $result = ($this->add)(StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Double stamp', -1));

        expect($result->rewards)->toBe([])
            ->and($result->enrollment->only(['current_stamps', 'completed_count']))->toBe(['current_stamps' => 7, 'completed_count' => 0]);
    });

    it('unlocks one reward per full card in a single add', function (): void {
        $result = ($this->add)(($this->qr)(21));

        expect($result->enrollment->current_stamps)->toBe(1)
            ->and(array_map(fn (Reward $reward): int => $reward->milestone, $result->rewards))->toBe([1, 2]);
    });
});

describe('progressive cards', function (): void {
    beforeEach(function (): void {
        $this->cardModel = $this->context->bypass(function (): LoyaltyCard {
            $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create([
                'mode' => CardMode::Progressive,
                'cooldown_min' => 0,
                'daily_cap' => null,
                'tiers' => [['stamps' => 10, 'reward' => 'Free item'], ['stamps' => 5, 'reward' => '10% off'], ['stamps' => 20, 'reward' => 'VIP']],
            ]);
            $card->businesses()->attach($this->tenants->a1);

            return $card;
        });
        $this->enrollment = $this->tenants->enroll(User::factory()->create(), $this->cardModel);
    });

    it('unlocks every tier crossed in one add, and never resets', function (): void {
        ($this->add)(($this->qr)(3));
        $result = ($this->add)(($this->qr)(8));

        expect($result->enrollment->only(['current_stamps', 'lifetime_stamps', 'completed_count']))->toBe(['current_stamps' => 11, 'lifetime_stamps' => 11, 'completed_count' => 2])
            ->and(array_map(fn (Reward $reward): array => [$reward->milestone, $reward->reward_text], $result->rewards))->toBe([[5, '10% off'], [10, 'Free item']]);
    });

    it('never unlocks a tier twice, even crossed again after a correction', function (): void {
        ($this->add)(($this->qr)(6));
        ($this->add)(StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Double stamp', -2));
        $result = ($this->add)(($this->qr)(2));

        expect($result->rewards)->toBe([])
            ->and($this->context->bypass(fn (): int => Reward::query()->where('enrollment_id', $this->enrollment->id)->count()))->toBe(1);
    });

    it('refuses malformed tiers when a card is saved', function (mixed $tiers): void {
        $this->context->bypass(fn () => LoyaltyCard::factory()->for($this->tenants->orgA)->create(['mode' => CardMode::Progressive, 'tiers' => $tiers]));
    })->throws(LogicException::class, 'tiers')->with([
        'none' => [null],
        'empty' => [[]],
        'zero stamps' => [[['stamps' => 0, 'reward' => 'Free']]],
        'no reward' => [[['stamps' => 5]]],
        'a blank reward' => [[['stamps' => 5, 'reward' => ' ']]],
        'a repeated threshold' => [[['stamps' => 5, 'reward' => 'A'], ['stamps' => 5, 'reward' => 'B']]],
        'stamps as text' => [[['stamps' => '5', 'reward' => 'A']]],
    ]);

    it('refuses the stamp cleanly when stored tiers are malformed', function (): void {
        DB::table('loyalty_cards')->where('id', $this->cardModel->id)->update(['tiers' => json_encode([['stamps' => '5', 'reward' => 'A']])]);

        expect(($this->rejection)(fn () => ($this->add)(($this->qr)())))->toBe(StampRejection::CardMisconfigured);
    });
});

describe('card mode', function (): void {
    it('lets a cyclic card keep an empty tiers list', function (): void {
        $card = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::factory()->for($this->tenants->orgA)->create(['tiers' => []]));

        expect($card->exists)->toBeTrue();
    });

    it('changes no tiers in a bulk update, where a card\'s mode is unknown', function (): void {
        $this->context->bypass(fn () => LoyaltyCard::query()->whereKey($this->tenants->cardB->id)->update(['tiers' => null]));
    })->throws(LogicException::class, 'tiers');

    it('needs well-formed tiers on a progressive card when it is saved', function (array $tiers): void {
        $this->context->bypass(fn () => LoyaltyCard::factory()->for($this->tenants->orgA)->create(['mode' => CardMode::Progressive, ...$tiers]));
    })->throws(LogicException::class, 'tiers')->with([
        'no tiers' => [[]],
        'a tier without stamps' => [['tiers' => [['reward' => 'Gift']]]],
    ]);

    it('is fixed once customers hold the card', function (string $how): void {
        $this->context->bypass(fn () => match ($how) {
            'switching a held card' => $this->tenants->cardA->forceFill(['mode' => CardMode::Progressive])->save(),
            'changing a held card\'s tiers' => $this->tenants->cardA->forceFill(['tiers' => [['stamps' => 5, 'reward' => 'Gift']]])->save(),
            'switching cards in bulk' => LoyaltyCard::query()->whereKey($this->tenants->cardB->id)->update(['mode' => CardMode::Progressive]),
        });
    })->throws(LogicException::class, 'mode')->with(['switching a held card', 'changing a held card\'s tiers', 'switching cards in bulk']);

    it('can change while nobody holds the card', function (): void {
        $this->context->bypass(fn () => $this->tenants->cardB->forceFill(['mode' => CardMode::Progressive, 'tiers' => [['stamps' => 5, 'reward' => 'Gift']]])->save());

        expect($this->context->bypass(fn (): ?CardMode => $this->tenants->cardB->fresh()?->mode))->toBe(CardMode::Progressive);
    });
});

describe('corrections', function (): void {
    it('takes stamps back where they were given, even on a card switched off or no longer honoured there', function (string $change): void {
        ($this->add)(($this->qr)(3));
        $this->context->bypass(fn () => $change === 'switched off'
            ? $this->tenants->cardA->forceFill(['active' => false])->save()
            : $this->tenants->cardA->businesses()->detach($this->tenants->a1->id));

        expect(($this->add)(StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Fraud', -3))->enrollment->current_stamps)->toBe(0);
    })->with(['switched off', 'no longer honoured']);

    it('takes no stamps back where none were given', function (): void {
        ($this->add)(($this->qr)(3));
        $this->context->bypass(fn () => $this->tenants->cardA->businesses()->detach($this->tenants->a2->id));

        expect(($this->rejection)(fn () => ($this->add)(StampRequest::correction($this->a2Location, $this->a2Staff, (string) Str::uuid(), 'Fraud', -1))))->toBe(StampRejection::NotHonoured)
            ->and(fn () => $this->tenants->stamp($this->enrollment, $this->tenants->a2, ['source' => StampSource::Correction, 'qty' => -1, 'reason' => 'Fraud']))->toThrow(LogicException::class, 'honours');
    });

    it('takes stamps back, never below zero', function (): void {
        ($this->card)(['cooldown_min' => 0, 'daily_cap' => null]);
        ($this->add)(($this->qr)(3));
        $result = ($this->add)(StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Wrong customer', -2));
        ($this->add)(($this->qr)(9));

        expect($result->enrollment->only(['current_stamps', 'lifetime_stamps']))->toBe(['current_stamps' => 1, 'lifetime_stamps' => 1])
            ->and(($this->rejection)(fn () => ($this->add)(StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Reward already given', -1))))->toBe(StampRejection::CorrectionBelowZero);
    });

    it('takes back at most the stamps this business gave', function (): void {
        ($this->card)(['cooldown_min' => 0]);
        ($this->add)(($this->qr)(2));
        ($this->add)(($this->qr)(3, at: $this->a2Location, staff: $this->a2Staff));

        expect(($this->rejection)(fn () => ($this->add)(StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Fraud', -3))))->toBe(StampRejection::CorrectionExceedsGiven)
            ->and(($this->add)(StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Fraud', -2))->enrollment->current_stamps)->toBe(3);
    });

    it('still corrects at an archived location, where nothing else is stamped', function (): void {
        ($this->add)(($this->qr)(2));
        $this->context->bypass(fn () => app(ArchiveLocation::class)->handle($this->a1Location));

        expect(($this->rejection)(fn () => ($this->add)(($this->qr)())))->toBe(StampRejection::SiteClosed)

            ->and(($this->add)(StampRequest::correction($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Fraud', -2))->enrollment->current_stamps)->toBe(0);
    });
});

describe('idempotency', function (): void {
    it('returns the rewards a retried stamp unlocked', function (): void {
        ($this->card)(['cooldown_min' => 0, 'daily_cap' => null]);
        $first = ($this->add)(($this->qr)(10, 'scan-full'));
        $retry = ($this->add)(($this->qr)(10, 'scan-full'));

        expect($retry->replayed)->toBeTrue()
            ->and(array_map(fn (Reward $reward): int => $reward->id, $retry->rewards))->toBe([$first->rewards[0]->id])
            ->and($first->rewards[0]->stamp_event_id)->toBe($first->event->id);
    });

    it('applies a retried stamp once', function (): void {
        $first = ($this->add)(($this->qr)(2, 'scan-1'));
        $retry = ($this->add)(($this->qr)(2, 'scan-1'));

        expect($retry->replayed)->toBeTrue()
            ->and($retry->event->id)->toBe($first->event->id)
            ->and($retry->enrollment->lifetime_stamps)->toBe(2)
            ->and($this->context->bypass(fn (): int => StampEvent::query()->count()))->toBe(1);
    });

    it('refuses the same key for a different stamp', function (string $change): void {
        ($this->add)(($this->qr)(2, 'scan-1'));
        $other = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);

        expect(($this->rejection)(fn () => match ($change) {
            'another quantity' => ($this->add)(($this->qr)(3, 'scan-1')),
            'another customer' => ($this->add)(($this->qr)(2, 'scan-1'), $other),
        }))->toBe(StampRejection::IdempotencyConflict);
    })->with(['another quantity', 'another customer']);

    it('applies a retried system stamp once', function (): void {
        $first = ($this->add)(StampRequest::system(StampSource::Birthday, $this->a1Location, 'birthday-2026', 2));
        $retry = ($this->add)(StampRequest::system(StampSource::Birthday, $this->a1Location, 'birthday-2026', 2));

        expect($retry->replayed)->toBeTrue()
            ->and($retry->event->id)->toBe($first->event->id)
            ->and($retry->enrollment->lifetime_stamps)->toBe(2);
    });

    it('replays a key only for the same person and reason', function (string $change): void {
        $colleague = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
        ($this->add)(StampRequest::manual($this->a1Location, $this->a1Staff, 'manual-1', 'Card forgotten', 1));

        expect(($this->rejection)(fn () => ($this->add)(match ($change) {
            'another staff member' => StampRequest::manual($this->a1Location, $colleague, 'manual-1', 'Card forgotten', 1),
            'another reason' => StampRequest::manual($this->a1Location, $this->a1Staff, 'manual-1', 'Something else', 1),
        })))->toBe(StampRejection::IdempotencyConflict);
    })->with(['another staff member', 'another reason']);

    it('checks who is asking before replaying a key', function (): void {
        ($this->add)(($this->qr)(1, 'scan-1'));

        ($this->add)(($this->qr)(1, 'scan-1', staff: $this->a2Staff));
    })->throws(LogicException::class, 'staff');

    it('scopes keys to the business', function (): void {
        ($this->add)(($this->qr)(1, 'scan-1'));
        $this->travel(21)->minutes();

        expect(($this->add)(($this->qr)(1, 'scan-1', $this->a2Location, $this->a2Staff))->replayed)->toBeFalse();
    });
});

describe('refusals', function (): void {
    it('refuses an inactive card', function (): void {
        ($this->card)(['active' => false]);

        expect(($this->rejection)(fn () => ($this->add)(($this->qr)())))->toBe(StampRejection::CardInactive);
    });

    it('refuses a card the business does not honour', function (): void {
        $b1Staff = $this->tenants->member(User::factory()->create(), $this->tenants->b1);

        expect(($this->rejection)(fn () => ($this->add)(($this->qr)(at: $this->tenants->locationOf($this->tenants->b1), staff: $b1Staff))))->toBe(StampRejection::NotHonoured);
    });

    it('refuses a closed business', function (): void {
        $this->context->bypass(fn () => app(ArchiveBusiness::class)->handle($this->tenants->a1));

        expect(($this->rejection)(fn () => ($this->add)(($this->qr)())))->toBe(StampRejection::SiteClosed);
    });

    it('stamps on a tap at a working stamper, with its tag and counter', function (): void {
        $stamper = $this->tenants->stamper($this->tenants->a1);

        $event = ($this->add)(StampRequest::nfc($stamper, 7, 3))->event;

        expect($event->only(['source', 'qty', 'stamper_id', 'nfc_tag_id', 'counter', 'location_id']))->toBe([
            'source' => StampSource::Nfc,
            'qty' => 3,
            'stamper_id' => $stamper->id,
            'nfc_tag_id' => $stamper->nfc_tag_id,
            'counter' => 7,
            'location_id' => $this->a1Location->id,
        ]);
    });

    it('never takes the same tap twice', function (): void {
        ($this->card)(['cooldown_min' => 0]);
        $stamper = $this->tenants->stamper($this->tenants->a1);
        ($this->add)(StampRequest::nfc($stamper, 7));

        expect(fn () => ($this->add)(StampRequest::nfc($stamper, 7)))->toThrow(UniqueConstraintViolationException::class)
            ->and($this->context->bypass(fn (): int => StampEvent::query()->count()))->toBe(1);
    });

    it('refuses a tap on a paused or ended stamper', function (string $state): void {
        $stamper = $this->tenants->stamper($this->tenants->a1);
        $this->context->bypass(fn () => $stamper->forceFill($state === 'paused' ? ['status' => StamperStatus::Disabled] : ['unassigned_at' => now()])->save());

        expect(($this->rejection)(fn () => ($this->add)(StampRequest::nfc($stamper, 7))))->toBe(StampRejection::StamperUnavailable);
    })->with(['paused', 'ended']);

    it('checks the request shape before anything else', function (Closure $request): void {
        $request->call($this);
    })->throws(InvalidArgumentException::class)->with([
        'a tap of 11' => fn (): StampRequest => StampRequest::nfc($this->tenants->stamper($this->tenants->a1), 7, 11),
        'a scan of 0' => fn (): StampRequest => ($this->qr)(0),
        'a scan of 51' => fn (): StampRequest => ($this->qr)(51),
        'a blank key' => fn (): StampRequest => ($this->qr)(1, ' '),
        'a manual stamp without a reason' => fn (): StampRequest => StampRequest::manual($this->a1Location, $this->a1Staff, 'k', ' ', 1),
        'a reason over 255 characters' => fn (): StampRequest => StampRequest::manual($this->a1Location, $this->a1Staff, 'k', str_repeat('a', 256), 1),
        'a correction of 0' => fn (): StampRequest => StampRequest::correction($this->a1Location, $this->a1Staff, 'k', 'Fix', 0),
        'a correction that adds stamps' => fn (): StampRequest => StampRequest::correction($this->a1Location, $this->a1Staff, 'k', 'Missed stamps', 2),
        'a system stamp as a scan' => fn (): StampRequest => StampRequest::system(StampSource::Qr, $this->a1Location, 'k'),
        'a system stamp without a key' => fn (): StampRequest => StampRequest::system(StampSource::Bonus, $this->a1Location, ' '),
    ]);
});

describe('callers', function (): void {
    it('lets staff pick for a manual stamp or a correction only customers their business can see', function (): void {
        ($this->card)(['cooldown_min' => 0]);
        ($this->add)(($this->qr)(at: $this->a2Location, staff: $this->a2Staff));
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);
        $manual = fn (): StampResult => app(AddStamps::class)->handle($this->enrollment, StampRequest::manual($this->a1Location, $this->a1Staff, (string) Str::uuid(), 'Card forgotten', 1));

        expect($manual)->toThrow(LogicException::class, 'can see');

        app(AddStamps::class)->handle($this->enrollment, ($this->qr)());

        expect($manual()->enrollment->lifetime_stamps)->toBe(3);
    });

    it('lets staff stamp at their own business, from their tenant', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff);

        expect(app(AddStamps::class)->handle($this->enrollment, ($this->qr)())->enrollment->lifetime_stamps)->toBe(1);
    });

    it('refuses a caller from another business or organization', function (string $caller): void {
        match ($caller) {
            'a sibling franchisee' => $this->context->set($this->tenants->orgA, $this->tenants->a2, businessRole: BusinessRole::Staff),
            'HQ without a business' => $this->context->set($this->tenants->orgA, orgAdmin: true),
            'another organization' => $this->context->set($this->tenants->orgB, $this->tenants->b1, businessRole: BusinessRole::Owner),
        };

        app(AddStamps::class)->handle($this->enrollment, ($this->qr)());
    })->throws(LogicException::class)->with(['a sibling franchisee', 'HQ without a business', 'another organization']);

    it('lets an org admin stamp at the organization\'s businesses, and nowhere else', function (): void {
        $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
        $otherHq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgB);

        expect(fn () => ($this->add)(($this->qr)(staff: $otherHq)))->toThrow(LogicException::class, 'staff')
            ->and(($this->add)(($this->qr)(staff: $hq))->enrollment->lifetime_stamps)->toBe(1);
    });

    it('keeps staff limited to one location to that location', function (): void {
        $otherLocation = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create());
        $limited = User::factory()->create();
        $this->context->bypass(fn () => $this->tenants->a1->members()->attach($limited, ['role' => BusinessRole::Staff->value, 'location_id' => $otherLocation->id]));

        expect(fn () => ($this->add)(($this->qr)(staff: $limited)))->toThrow(LogicException::class, 'staff')
            ->and(($this->add)(($this->qr)(at: $otherLocation, staff: $limited))->event->location_id)->toBe($otherLocation->id);
    });

    it('refuses staff who do not work at the business', function (): void {
        ($this->add)(($this->qr)(staff: $this->a2Staff));
    })->throws(LogicException::class, 'staff');
});

describe('locks and events', function (): void {
    it('locks a tap\'s stamper before the enrollment, like /t', function (): void {
        $stamper = $this->tenants->stamper($this->tenants->a1);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        ($this->add)(StampRequest::nfc($stamper, 1));

        $stamperLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "stampers"') && str_contains($sql, 'for update'));
        $enrollmentLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, '"card_enrollments"') && str_contains($sql, 'for update'));

        expect($stamperLock)->toBeInt()
            ->and($enrollmentLock)->toBeInt()
            ->and($stamperLock)->toBeLessThan($enrollmentLock);
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Row locks compile on Postgres only');

    it('locks the enrollment row', function (): void {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        ($this->add)(($this->qr)());

        $lock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, '"card_enrollments"') && str_contains($sql, 'for update'));
        $capSum = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'sum("qty")'));

        expect($lock)->toBeInt()
            ->and($capSum)->toBeInt()
            ->and($lock)->toBeLessThan($capSum);
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Row locks compile on Postgres only');

    it('announces the change once the stamp is committed', function (): void {
        $seen = [];
        Event::listen(EnrollmentChanged::class, function (EnrollmentChanged $event) use (&$seen): void {
            $seen[] = $event;
        });
        ($this->card)(['cooldown_min' => 0, 'daily_cap' => null]);

        $result = ($this->add)(($this->qr)(10));
        ($this->add)(($this->qr)(1, 'scan-1'));
        ($this->add)(($this->qr)(1, 'scan-1'));

        expect($seen)->toHaveCount(2)
            ->and($seen[0]->enrollmentId)->toBe($this->enrollment->id)
            ->and($seen[0]->businessId)->toBe($this->tenants->a1->id)
            ->and($seen[0]->rewardIds)->toBe([$result->rewards[0]->id]);
    });

    it('announces nothing when the surrounding transaction rolls back', function (): void {
        $seen = 0;
        Event::listen(EnrollmentChanged::class, function () use (&$seen): void {
            $seen++;
        });

        try {
            DB::transaction(function (): void {
                ($this->add)(($this->qr)());

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        expect($seen)->toBe(0)
            ->and($this->context->bypass(fn (): int => StampEvent::query()->count()))->toBe(0);
    });
});
