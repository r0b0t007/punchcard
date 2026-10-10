<?php

declare(strict_types=1);

use App\Actions\Tenancy\CreateIndependentBusiness;
use App\Enums\BusinessCategory;
use App\Enums\CardMode;
use App\Enums\KitOrderStatus;
use App\Enums\OnboardingStep;
use App\Enums\PlatformRole;
use App\Enums\RewardType;
use App\Filament\Resources\Businesses\Pages\ListBusinesses;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\KitOrder;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| The onboarding wizard's last steps (CHW-31, spec B2)
|--------------------------------------------------------------------------
|
| The owner sets up the first loyalty card (10 stamps and the category's
| reward by default; the full card builder is CHW-32), then where to ship
| the stamper kit. The kit order is the last step: the business is then
| set up, and its owner lands on its dashboard.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->context = app(TenantContext::class);
    $this->owner = User::factory()->create(['name' => 'Salma Idrissi']);
    $this->business = app(CreateIndependentBusiness::class)->handle($this->owner, 'Café Marshan', BusinessCategory::Cafe);
    $this->fresh = fn (): Business => $this->context->bypass(fn (): Business => $this->business->refresh());
    $this->context->bypass(function (): void {
        Location::factory()->for($this->business)->create(['name' => 'Marshan', 'address' => '12 rue de la Plage', 'timezone' => 'Africa/Casablanca']);
        $this->business->forceFill(['onboarding_step' => OnboardingStep::Card])->save();
    });
    $this->cards = fn (): array => $this->context->bypass(fn (): array => LoyaltyCard::query()->where('organization_id', $this->business->organization_id)->get()->all());
});

describe('the card step', function (): void {
    it('suggests ten stamps and the category\'s reward', function (): void {
        $this->actingAs($this->owner)->get(route('onboarding.step', 'card'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('onboarding/card')
                ->where('card.stampsRequired', 10)
                ->where('card.rewardText', 'Free coffee')
                ->where('businessName', 'Café Marshan'));
    });

    it('creates a cyclic card the business honours, and updates it when the step is done again', function (): void {
        $this->actingAs($this->owner)->put(route('onboarding.card'), ['reward_text' => 'Free mint tea', 'stamps_required' => 8])
            ->assertRedirect(route('onboarding.step', 'shipping'));
        $this->context->bypass(fn () => $this->business->forceFill(['onboarding_step' => OnboardingStep::Card])->save());
        $this->put(route('onboarding.card'), ['reward_text' => 'Free espresso', 'stamps_required' => 9]);

        $cards = ($this->cards)();
        expect($cards)->toHaveCount(1)
            ->and($cards[0]->reward_text)->toBe('Free espresso')
            ->and($cards[0]->stamps_required)->toBe(9)
            ->and($cards[0]->mode)->toBe(CardMode::Cyclic)
            ->and($cards[0]->reward_type)->toBe(RewardType::Item)
            ->and($this->context->bypass(fn (): bool => $cards[0]->businesses()->whereKey($this->business->id)->exists()))->toBeTrue()
            ->and(($this->fresh)()->onboarding_step)->toBe(OnboardingStep::Shipping);
    });

    it('refuses a card outside 5 to 50 stamps, or without a reward', function (array $fields, string $error): void {
        $this->actingAs($this->owner)->put(route('onboarding.card'), [...['reward_text' => 'Free coffee', 'stamps_required' => 10], ...$fields])
            ->assertSessionHasErrors($error);

        expect(($this->cards)())->toBe([]);
    })->with([
        'too few stamps' => [['stamps_required' => 4], 'stamps_required'],
        'too many stamps' => [['stamps_required' => 51], 'stamps_required'],
        'no reward' => [['reward_text' => ''], 'reward_text'],
    ]);

    it('leaves a card customers hold as it is', function (): void {
        $this->actingAs($this->owner)->put(route('onboarding.card'), ['reward_text' => 'Free mint tea', 'stamps_required' => 8]);
        $card = ($this->cards)()[0];
        $this->context->bypass(fn () => CardEnrollment::factory()->for($card, 'card')->for(User::factory()->create())->create());
        $this->context->bypass(fn () => $this->business->forceFill(['onboarding_step' => OnboardingStep::Card])->save());

        $this->put(route('onboarding.card'), ['reward_text' => 'Free espresso', 'stamps_required' => 12])->assertSessionHasErrors('reward_text');

        expect(($this->cards)()[0]->stamps_required)->toBe(8);
    });
});

describe('the shipping step', function (): void {
    beforeEach(fn () => $this->context->bypass(fn () => $this->business->forceFill(['onboarding_step' => OnboardingStep::Shipping])->save()));

    it('suggests the owner\'s name and the location\'s address', function (): void {
        $this->actingAs($this->owner)->get(route('onboarding.step', 'shipping'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('onboarding/shipping')
                ->where('kit.recipientName', 'Salma Idrissi')
                ->where('kit.address', '12 rue de la Plage'));
    });

    it('requests the stamper kit, finishes setup and lands the owner on its dashboard', function (): void {
        $this->actingAs($this->owner)->put(route('onboarding.shipping'), [
            'recipient_name' => 'Salma Idrissi', 'phone' => '+212 6 12 34 56 78', 'address' => '12 rue de la Plage', 'city' => 'Tanger', 'postal_code' => '90000',
        ])->assertRedirect(route('dashboard'));

        $order = $this->context->bypass(fn (): KitOrder => KitOrder::query()->where('business_id', $this->business->id)->sole());
        expect($order->status)->toBe(KitOrderStatus::Requested)
            ->and($order->city)->toBe('Tanger')
            ->and($order->location_id)->toBe($this->context->bypass(fn (): ?int => $this->business->firstLocation?->id))
            ->and(($this->fresh)()->onboarded_at)->not->toBeNull()
            ->and(session(SetTenant::SESSION_KEY))->toBe('business:'.$this->business->id);

        $this->get(route('dashboard'))->assertOk();
    });

    it('refuses a phone number that is not one', function (): void {
        $this->actingAs($this->owner)->put(route('onboarding.shipping'), [
            'recipient_name' => 'Salma', 'phone' => 'call me', 'address' => '12 rue de la Plage', 'city' => 'Tanger',
        ])->assertSessionHasErrors('phone');

        expect(($this->fresh)()->onboarded_at)->toBeNull();
    });

    it('keeps one requested kit per business', function (): void {
        $this->context->bypass(function (): void {
            KitOrder::factory()->for($this->business)->create();

            expect(fn () => DB::transaction(fn () => KitOrder::factory()->for($this->business)->create()))->toThrow(QueryException::class);
        });
    });
});

it('keeps businesses still being set up out of the verification queue', function (): void {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->actingAs($admin);
    Filament\Facades\Filament::setCurrentPanel('admin');
    Filament\Facades\Filament::setServingStatus();

    $this->context->bypass(fn () => Livewire\Livewire::test(ListBusinesses::class)
        ->assertCanNotSeeTableRecords([$this->business])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$this->business]));

    Filament\Facades\Filament::setServingStatus(false);
});

it('saves neither the card nor the kit before their step', function (): void {
    $this->context->bypass(fn () => $this->business->forceFill(['onboarding_step' => OnboardingStep::Location])->save());

    $this->actingAs($this->owner)->put(route('onboarding.card'), ['reward_text' => 'Free coffee', 'stamps_required' => 10])
        ->assertRedirect(route('onboarding.step', 'location'));
    $this->put(route('onboarding.shipping'), ['recipient_name' => 'Salma', 'phone' => '+212612345678', 'address' => '12 rue de la Plage', 'city' => 'Tanger'])
        ->assertRedirect(route('onboarding.step', 'location'));

    expect(($this->cards)())->toBe([])
        ->and($this->context->bypass(fn (): int => KitOrder::query()->count()))->toBe(0)
        ->and(($this->fresh)()->onboarded_at)->toBeNull();
});

it('locks the business before looking for its card or kit, so two tabs make one of each, on Postgres', function (string $step, string $table, array $fields): void {
    $this->context->bypass(fn () => $this->business->forceFill(['onboarding_step' => OnboardingStep::from($step)])->save());
    DB::enableQueryLog();

    $this->actingAs($this->owner)->put(route('onboarding.'.$step), $fields);

    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    $business = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "businesses"') && str_contains($sql, 'for no key update'));
    $lookup = $queries->search(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, "from \"{$table}\""));

    expect($business)->toBeInt()
        ->and($lookup)->toBeInt()
        ->and($business)->toBeLessThan($lookup);
})->with([
    'card' => ['card', 'loyalty_cards', ['reward_text' => 'Free coffee', 'stamps_required' => 10]],
    'kit' => ['shipping', 'kit_orders', ['recipient_name' => 'Salma', 'phone' => '+212612345678', 'address' => '12 rue de la Plage', 'city' => 'Tanger']],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Row locks compile on Postgres only');
