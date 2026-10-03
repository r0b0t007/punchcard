<?php

declare(strict_types=1);

use App\Actions\Tenancy\ResolveTenant;
use App\Enums\OrganizationType;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\NfcTag;
use App\Models\Organization;
use App\Models\Reward;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;

/*
|--------------------------------------------------------------------------
| Demo data (CHW-21)
|--------------------------------------------------------------------------
|
| An independent café (1 business, 2 locations, 1 card, 3 stampers, 20
| customers) and a franchise (1 organization, 2 franchisee businesses
| sharing 1 card). The data has to obey every rule the product does: the
| counters and rewards follow the ledger, stamps respect the cooldown and
| cap and are never in the future, tag counters rise with time, and each
| seeded login sees exactly what tenancy allows.
|
*/

describe('the demo', function (): void {
    beforeEach(function (): void {
        $this->seed(DemoSeeder::class);
        $this->context = app(TenantContext::class);
    });

    it('has an independent café with 2 locations, 1 card, 3 stampers and 20 customers', function (): void {
        $this->context->bypass(function (): void {
            $cafe = Organization::query()->where('slug', 'cafe-hafa')->sole();
            $business = Business::query()->where('organization_id', $cafe->id)->sole();
            $card = LoyaltyCard::query()->where('organization_id', $cafe->id)->sole();

            expect($cafe->type)->toBe(OrganizationType::Independent)
                ->and(Location::query()->where('business_id', $business->id)->count())->toBe(2)
                ->and(Stamper::query()->where('business_id', $business->id)->count())->toBe(3)
                ->and($card->businesses()->pluck('businesses.id')->all())->toBe([$business->id])
                ->and(CardEnrollment::query()->where('card_id', $card->id)->count())->toBe(20);
        });
    });

    it('has a franchise of two businesses sharing one card', function (): void {
        $this->context->bypass(function (): void {
            $franchise = Organization::query()->where('slug', 'atlas-coffee')->sole();
            $businesses = Business::query()->where('organization_id', $franchise->id)->orderBy('id')->pluck('id')->all();
            $card = LoyaltyCard::query()->where('organization_id', $franchise->id)->sole();

            expect($franchise->type)->toBe(OrganizationType::Franchise)
                ->and($businesses)->toHaveCount(2)
                ->and($card->businesses()->orderBy('businesses.id')->pluck('businesses.id')->all())->toBe($businesses);
        });
    });

    it('keeps every enrollment\'s progress and rewards equal to its ledger', function (): void {
        $this->context->bypass(function (): void {
            foreach (CardEnrollment::query()->with('card')->get() as $enrollment) {
                $events = StampEvent::query()->where('enrollment_id', $enrollment->id)->orderBy('created_at')->get();
                $lifetime = (int) $events->sum('qty');
                $completed = intdiv($lifetime, $enrollment->card->stamps_required);
                $milestones = Reward::query()->where('enrollment_id', $enrollment->id)->orderBy('milestone')->pluck('milestone')->all();

                expect($enrollment->lifetime_stamps)->toBe($lifetime)
                    ->and($enrollment->completed_count)->toBe($completed)
                    ->and($enrollment->current_stamps)->toBe($lifetime % $enrollment->card->stamps_required)
                    ->and($enrollment->last_stamp_at?->equalTo($events->last()?->created_at))->toBeTrue()
                    ->and($enrollment->created_at?->equalTo($events->first()?->created_at))->toBeTrue()
                    ->and($milestones)->toBe($completed === 0 ? [] : range(1, $completed));
            }
        });
    });

    it('stamps only in the past, within each card\'s cooldown and daily cap', function (): void {
        $this->context->bypass(function (): void {
            foreach (CardEnrollment::query()->with('card')->get() as $enrollment) {
                $times = StampEvent::query()->where('enrollment_id', $enrollment->id)->orderBy('created_at')->pluck('created_at');
                $perDay = $times->countBy(fn ($at): string => $at->setTimezone('Africa/Casablanca')->toDateString());

                expect($times->last()->isPast())->toBeTrue()
                    ->and($perDay->max())->toBeLessThanOrEqual($enrollment->card->daily_cap);

                foreach ($times->sliding(2) as $pair) {
                    expect($pair->first()->diffInMinutes($pair->last()))->toBeGreaterThanOrEqual($enrollment->card->cooldown_min);
                }
            }
        });
    });

    it('redeems the earlier rewards at a business of the program, after unlocking, and keeps the latest available', function (): void {
        $this->context->bypass(function (): void {
            $statuses = Reward::query()->pluck('status')->unique()->sortBy(fn (RewardStatus $status): string => $status->value)->values()->all();

            expect($statuses)->toBe([RewardStatus::Available, RewardStatus::Redeemed]);

            foreach (Reward::query()->where('status', RewardStatus::Redeemed)->with(['enrollment.card', 'redeemedLocation'])->get() as $reward) {
                expect($reward->enrollment->card->businesses()->whereKey($reward->redeemed_business_id)->exists())->toBeTrue()
                    ->and($reward->redeemedLocation?->business_id)->toBe($reward->redeemed_business_id)
                    ->and($reward->redeemed_at?->greaterThan($reward->unlocked_at))->toBeTrue()
                    ->and($reward->redeemed_at?->isPast())->toBeTrue();
            }
        });
    });

    it('keeps every tag\'s counter rising with time and at or above its taps', function (): void {
        $this->context->bypass(function (): void {
            foreach (NfcTag::query()->get() as $tag) {
                $counters = StampEvent::query()->where('nfc_tag_id', $tag->id)->orderBy('created_at')->orderBy('id')->pluck('counter')->all();
                $sorted = $counters;
                sort($sorted);

                expect($counters)->not->toBe([])
                    ->and($counters)->toBe($sorted)
                    ->and($tag->last_counter)->toBe(max($counters));
            }
        });
    });

    it('stamps each customer of both franchisees at both', function (): void {
        $this->context->bypass(function (): void {
            $franchise = Organization::query()->where('slug', 'atlas-coffee')->sole();
            $visitedBoth = CardEnrollment::query()
                ->where('organization_id', $franchise->id)
                ->get()
                ->filter(fn (CardEnrollment $enrollment): bool => StampEvent::query()->where('enrollment_id', $enrollment->id)->distinct()->count('business_id') === 2);

            expect($visitedBoth)->toHaveCount(4);
        });
    });

    it('shows each seeded login exactly the customers tenancy allows', function (string $email, Closure $expected): void {
        $expectedIds = $this->context->bypass(fn (): array => $expected()->sort()->values()->all());

        app(ResolveTenant::class)->handle(User::query()->where('email', $email)->sole());

        expect(CardEnrollment::query()->orderBy('id')->pluck('id')->all())->toBe($expectedIds);
    })->with([
        // Customers who stamped at Tangier, Tangier-only and both: none of Tétouan's own.
        'Tangier owner' => ['owner@a1.demo.test', fn () => StampEvent::query()
            ->whereIn('business_id', Business::query()->where('slug', 'atlas-coffee-tangier')->select('id'))
            ->whereIn('source', StampSource::presenceValues())->distinct()->pluck('enrollment_id')],
        'Tétouan owner' => ['owner@a2.demo.test', fn () => StampEvent::query()
            ->whereIn('business_id', Business::query()->where('slug', 'atlas-coffee-tetouan')->select('id'))
            ->whereIn('source', StampSource::presenceValues())->distinct()->pluck('enrollment_id')],
        'franchise HQ' => ['hq@franchise.demo.test', fn () => CardEnrollment::query()
            ->whereIn('organization_id', Organization::query()->where('slug', 'atlas-coffee')->select('id'))->pluck('id')],
        'café owner' => ['owner@cafe.demo.test', fn () => CardEnrollment::query()
            ->whereIn('organization_id', Organization::query()->where('slug', 'cafe-hafa')->select('id'))->pluck('id')],
        'café staff' => ['staff@cafe.demo.test', fn () => CardEnrollment::query()
            ->whereIn('organization_id', Organization::query()->where('slug', 'cafe-hafa')->select('id'))->pluck('id')],
    ]);

    it('keeps each franchisee\'s own customers, stampers and stamps from the other', function (): void {
        [$tangierOnly, $tetouanOnly, $demoCustomer] = $this->context->bypass(function (): array {
            $tangier = Business::query()->where('slug', 'atlas-coffee-tangier')->sole();
            $tetouan = Business::query()->where('slug', 'atlas-coffee-tetouan')->sole();
            $atBusiness = fn (Business $business): array => StampEvent::query()->where('business_id', $business->id)->distinct()->pluck('enrollment_id')->all();

            return [
                array_values(array_diff($atBusiness($tangier), $atBusiness($tetouan))),
                array_values(array_diff($atBusiness($tetouan), $atBusiness($tangier))),
                CardEnrollment::query()->where('organization_id', $tangier->organization_id)
                    ->where('user_id', User::query()->where('email', 'customer@demo.test')->value('id'))->value('id'),
            ];
        });

        app(ResolveTenant::class)->handle(User::query()->where('email', 'owner@a1.demo.test')->sole());
        $atTangier = CardEnrollment::query()->pluck('id')->all();
        $tangierBusinesses = array_unique([
            ...Stamper::query()->pluck('business_id')->all(),
            ...StampEvent::query()->pluck('business_id')->all(),
        ]);

        app(ResolveTenant::class)->handle(User::query()->where('email', 'owner@a2.demo.test')->sole());
        $atTetouan = CardEnrollment::query()->pluck('id')->all();

        expect(array_intersect($atTangier, $tetouanOnly))->toBe([])
            ->and(array_intersect($atTetouan, $tangierOnly))->toBe([])
            ->and($atTangier)->toContain($demoCustomer)
            ->and($atTetouan)->toContain($demoCustomer)
            ->and(array_values($tangierBusinesses))->toHaveCount(1);
    });

    it('lets the demo customer hold a card in both programs', function (): void {
        $customer = User::query()->where('email', 'customer@demo.test')->sole();

        expect($this->context->bypass(fn (): int => CardEnrollment::query()->where('user_id', $customer->id)->count()))->toBe(2);
    });
});

it('seeds only in the local and testing environments', function (string $environment, string $seeder): void {
    $this->app->detectEnvironment(fn (): string => $environment);

    expect(fn () => app($seeder)->run())->toThrow(RuntimeException::class, 'only in the local and testing environments')
        ->and(User::query()->count())->toBe(0);
})->with(['production', 'staging', 'prod'])->with([DemoSeeder::class, DatabaseSeeder::class]);
