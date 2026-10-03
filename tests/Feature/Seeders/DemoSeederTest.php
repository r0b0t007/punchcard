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
use Database\Seeders\DemoSeeder;

/*
|--------------------------------------------------------------------------
| Demo data (CHW-21)
|--------------------------------------------------------------------------
|
| An independent café (1 business, 2 locations, 1 card, 3 stampers, 20
| customers) and a franchise (1 organization, 2 franchisee businesses
| sharing 1 card). The data has to obey every rule the product does: the
| counters and rewards follow the ledger, the tags' counters cover their
| taps, and each seeded login sees what tenancy allows.
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
                $events = StampEvent::query()->where('enrollment_id', $enrollment->id)->get();
                $lifetime = (int) $events->sum('qty');
                $required = $enrollment->card->stamps_required;
                $completed = intdiv($lifetime, $required);
                $milestones = Reward::query()->where('enrollment_id', $enrollment->id)->orderBy('milestone')->pluck('milestone')->all();

                expect($enrollment->lifetime_stamps)->toBe($lifetime)
                    ->and($enrollment->completed_count)->toBe($completed)
                    ->and($enrollment->current_stamps)->toBe($lifetime % $required)
                    ->and($enrollment->last_stamp_at?->equalTo($events->max('created_at')))->toBeTrue()
                    ->and($milestones)->toBe($completed === 0 ? [] : range(1, $completed));
            }
        });
    });

    it('redeems some rewards at a business of the program, and keeps the rest available', function (): void {
        $this->context->bypass(function (): void {
            $statuses = Reward::query()->pluck('status')->unique()->sortBy(fn (RewardStatus $status): string => $status->value)->values()->all();

            expect($statuses)->toBe([RewardStatus::Available, RewardStatus::Redeemed]);

            foreach (Reward::query()->where('status', RewardStatus::Redeemed)->with(['enrollment.card', 'redeemedLocation'])->get() as $reward) {
                expect($reward->enrollment->card->businesses()->whereKey($reward->redeemed_business_id)->exists())->toBeTrue()
                    ->and($reward->redeemedLocation?->business_id)->toBe($reward->redeemed_business_id);
            }
        });
    });

    it('keeps every tag\'s counter at or above its taps', function (): void {
        $this->context->bypass(function (): void {
            foreach (NfcTag::query()->get() as $tag) {
                $highest = (int) StampEvent::query()->where('nfc_tag_id', $tag->id)->max('counter');

                expect($highest)->toBeGreaterThan(0)
                    ->and($tag->last_counter)->toBeGreaterThanOrEqual($highest);
            }
        });
    });

    it('shows each seeded login what tenancy allows', function (string $email): void {
        $user = User::query()->where('email', $email)->sole();
        $expected = $this->context->bypass(function () use ($email): array {
            $franchise = Organization::query()->where('slug', 'atlas-coffee')->sole();
            $tangier = Business::query()->where('slug', 'atlas-coffee-tangier')->sole();
            $cafe = Organization::query()->where('slug', 'cafe-hafa')->sole();

            return match ($email) {
                // A franchisee sees only the customers who stamped there.
                'owner@a1.demo.test' => StampEvent::query()
                    ->where('business_id', $tangier->id)
                    ->whereIn('source', StampSource::presenceValues())
                    ->distinct()->orderBy('enrollment_id')->pluck('enrollment_id')->all(),
                'hq@franchise.demo.test' => CardEnrollment::query()->where('organization_id', $franchise->id)->orderBy('id')->pluck('id')->all(),
                'owner@cafe.demo.test' => CardEnrollment::query()->where('organization_id', $cafe->id)->orderBy('id')->pluck('id')->all(),
            };
        });

        app(ResolveTenant::class)->handle($user);

        expect(CardEnrollment::query()->orderBy('id')->pluck('id')->all())->toBe($expected);
    })->with(['owner@a1.demo.test', 'hq@franchise.demo.test', 'owner@cafe.demo.test']);

    it('hides the franchise\'s other customers from a franchisee', function (): void {
        app(ResolveTenant::class)->handle(User::query()->where('email', 'owner@a1.demo.test')->sole());
        $seenAtTangier = CardEnrollment::query()->count();

        app(ResolveTenant::class)->handle(User::query()->where('email', 'hq@franchise.demo.test')->sole());

        expect($seenAtTangier)->toBeGreaterThan(0)
            ->and($seenAtTangier)->toBeLessThan(CardEnrollment::query()->count());
    });

    it('lets the demo customer hold a card in both programs', function (): void {
        $customer = User::query()->where('email', 'customer@demo.test')->sole();

        expect($this->context->bypass(fn (): int => CardEnrollment::query()->where('user_id', $customer->id)->count()))->toBe(2);
    });
});

it('never runs in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    app(DemoSeeder::class)->run();
})->throws(RuntimeException::class, 'never runs in production');
