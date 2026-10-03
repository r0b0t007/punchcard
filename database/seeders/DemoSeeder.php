<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Tenancy\CreateIndependentBusiness;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\CardMode;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\RewardStatus;
use App\Enums\RewardType;
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
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo data for local development and UI work (CHW-21): an independent café
 * (1 business, 2 locations, 1 card, 3 stampers, 20 customers) and a
 * franchise (1 organization, 2 franchisee businesses sharing 1 card), with
 * customers who stamped at one franchisee, the other, or both.
 *
 * It follows every product rule: writes go through bypass() like the admin
 * and stamp Actions do, each enrollment's counters and rewards follow its
 * stamp events, and each tag's counter covers its taps. Logins (password
 * "password"): owner@cafe.demo.test, staff@cafe.demo.test,
 * hq@franchise.demo.test, owner@a1.demo.test (Tangier), owner@a2.demo.test
 * (Tétouan) and customer@demo.test, a member of both programs.
 *
 * Known passwords, so it never runs in production.
 */
final class DemoSeeder extends Seeder
{
    /** @var array<int, int> Each tag's last SUN counter, by tag id. */
    private array $tagCounters = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CreateIndependentBusiness $createIndependentBusiness,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder creates accounts with a known password; it never runs in production.');
        }

        fake()->seed(2026);

        $this->context->bypass(function (): void {
            $customer = $this->user('Salma Bennani', 'customer@demo.test');

            $this->seedCafe($customer);
            $this->seedFranchise($customer);
        });
    }

    private function seedCafe(User $customer): void
    {
        $owner = $this->user('Yasmine Alaoui', 'owner@cafe.demo.test');
        $staff = $this->user('Karim Idrissi', 'staff@cafe.demo.test');

        $business = $this->createIndependentBusiness->handle($owner, 'Café Hafa', 'cafe');
        $business->forceFill(['status' => BusinessStatus::Verified])->save();
        $business->members()->attach($staff, ['role' => BusinessRole::Staff->value]);

        $terrace = $this->location($business, 'Hafa terrace', 'Avenue Hafa, Tangier');
        $kasbah = $this->location($business, 'Hafa Kasbah', 'Rue de la Kasbah, Tangier');
        $card = $this->card($business->organization_id, 'Hafa card', 'Free mint tea', [$business]);
        $stampers = [
            $this->stamper($business, $terrace, 'Terrace counter'),
            $this->stamper($business, $terrace, 'Terrace bar'),
            $this->stamper($business, $kasbah, 'Kasbah counter'),
        ];

        $customers = [$customer, ...User::factory(19)->create()->all()];

        // Each customer uses one to three of the stampers, served by the owner or the staff member.
        foreach ($customers as $member) {
            $visitedAt = [];
            $first = fake()->numberBetween(0, 2);

            foreach (range(0, fake()->numberBetween(0, 2)) as $offset) {
                $visitedAt[] = [$business, $stampers[($first + $offset) % 3], $offset % 2 === 0 ? $owner : $staff];
            }

            $this->member($card, $member, $visitedAt);
        }
    }

    private function seedFranchise(User $customer): void
    {
        $hq = $this->user('Omar Tazi', 'hq@franchise.demo.test');

        $franchise = new Organization;
        $franchise->forceFill([
            'name' => 'Atlas Coffee', 'slug' => 'atlas-coffee', 'type' => OrganizationType::Franchise, 'brand_color' => '#0F4C81',
        ])->save();
        $franchise->admins()->attach($hq, ['role' => OrganizationRole::OrgAdmin->value]);

        [$tangier, $tangierOwner, $tangierStamper] = $this->franchisee($franchise, 'Tangier', 'owner@a1.demo.test', 'Nadia Chraibi');
        [$tetouan, $tetouanOwner, $tetouanStamper] = $this->franchisee($franchise, 'Tétouan', 'owner@a2.demo.test', 'Hamza Benjelloun');
        $card = $this->card($franchise->id, 'Atlas card', 'Free espresso', [$tangier, $tetouan]);

        $atTangier = [$tangier, $tangierStamper, $tangierOwner];
        $atTetouan = [$tetouan, $tetouanStamper, $tetouanOwner];

        // Six customers of each franchisee only, and four of both, the demo customer among them.
        foreach (User::factory(6)->create() as $member) {
            $this->member($card, $member, [$atTangier]);
        }

        foreach (User::factory(6)->create() as $member) {
            $this->member($card, $member, [$atTetouan]);
        }

        foreach ([$customer, ...User::factory(3)->create()->all()] as $member) {
            $this->member($card, $member, [$atTangier, $atTetouan]);
        }
    }

    /**
     * @return array{0: Business, 1: User, 2: Stamper}
     */
    private function franchisee(Organization $franchise, string $city, string $email, string $ownerName): array
    {
        $owner = $this->user($ownerName, $email);

        $business = new Business;
        $business->forceFill([
            'organization_id' => $franchise->id, 'name' => "Atlas Coffee {$city}", 'slug' => 'atlas-coffee-'.Str::slug($city),
            'category' => 'cafe', 'status' => BusinessStatus::Verified,
        ])->save();
        $business->members()->attach($owner, ['role' => BusinessRole::Owner->value]);

        $location = $this->location($business, "Atlas {$city}", "Boulevard Mohammed V, {$city}");

        return [$business, $owner, $this->stamper($business, $location, 'Counter')];
    }

    /**
     * Enrolls the customer and records their visits as stamp events (taps and
     * staff scans over the last 60 days), then sets the counters and rewards
     * those events give, as the stamp Action would.
     *
     * @param  list<array{0: Business, 1: Stamper, 2: User}>  $visitedAt  where they stamp, on which stamper, served by whom
     */
    private function member(LoyaltyCard $card, User $customer, array $visitedAt): void
    {
        $enrollment = (new CardEnrollment)->forceFill([
            'card_id' => $card->id,
            'user_id' => $customer->id,
            'referral_code' => strtoupper(fake()->unique()->bothify('????####')),
        ]);
        $enrollment->save();

        $times = collect(range(1, fake()->numberBetween(1, 25)))
            ->map(fn (): Carbon => Carbon::now()->subDays(fake()->numberBetween(0, 60))->setTime(fake()->numberBetween(8, 21), fake()->numberBetween(0, 59)))
            ->sort()
            ->values();

        $events = $times->map(function (Carbon $at) use ($enrollment, $visitedAt): StampEvent {
            [$business, $stamper, $staff] = fake()->randomElement($visitedAt);

            return $this->stamp($enrollment, $business, $stamper, $staff, $at);
        });

        $lifetime = $events->count();
        $completed = intdiv($lifetime, $card->stamps_required);

        $enrollment->forceFill([
            'lifetime_stamps' => $lifetime,
            'completed_count' => $completed,
            'current_stamps' => $lifetime % $card->stamps_required,
            'last_stamp_at' => $times->last(),
        ])->save();

        for ($milestone = 1; $milestone <= $completed; $milestone++) {
            $unlockedAt = $times[$milestone * $card->stamps_required - 1];
            [$business, $stamper, $staff] = fake()->randomElement($visitedAt);
            // The latest reward is still waiting; earlier ones were redeemed a day later.
            $redeemed = $milestone < $completed;

            (new Reward)->forceFill([
                'enrollment_id' => $enrollment->id,
                'mode' => CardMode::Cyclic,
                'milestone' => $milestone,
                'reward_type' => $card->reward_type,
                'reward_value' => $card->reward_value,
                'reward_text' => $card->reward_text,
                'unlocked_at' => $unlockedAt,
                'status' => $redeemed ? RewardStatus::Redeemed : RewardStatus::Available,
                'redeemed_at' => $redeemed ? $unlockedAt->copy()->addDay()->min(Carbon::now()) : null,
                'redeemed_by' => $redeemed ? $staff->id : null,
                'redeemed_business_id' => $redeemed ? $business->id : null,
                'redeemed_location_id' => $redeemed ? $stamper->location_id : null,
            ])->save();
        }
    }

    /** One stamp: a tap on the stamper (its tag's next counter) or a staff scan of the member QR. */
    private function stamp(CardEnrollment $enrollment, Business $business, Stamper $stamper, User $staff, Carbon $at): StampEvent
    {
        $tap = fake()->boolean(70);
        $event = (new StampEvent)->forceFill([
            'enrollment_id' => $enrollment->id,
            'business_id' => $business->id,
            'location_id' => $stamper->location_id,
            'source' => $tap ? StampSource::Nfc : StampSource::Qr,
            'qty' => 1,
            'stamper_id' => $tap ? $stamper->id : null,
            'nfc_tag_id' => $tap ? $stamper->nfc_tag_id : null,
            'counter' => $tap ? $this->nextCounter($stamper->nfc_tag_id) : null,
            'staff_id' => $tap ? null : $staff->id,
            'idempotency_key' => $tap ? null : (string) Str::uuid(),
            'created_at' => $at,
        ]);
        $event->save();

        if ($tap) {
            NfcTag::query()->whereKey($stamper->nfc_tag_id)->update(['last_counter' => $this->tagCounters[$stamper->nfc_tag_id]]);
        }

        return $event;
    }

    /** Taps skip a few counter values (test taps, rejected ones), as real tags do. */
    private function nextCounter(int $tagId): int
    {
        $this->tagCounters[$tagId] = ($this->tagCounters[$tagId] ?? 0) + fake()->numberBetween(1, 3);

        return $this->tagCounters[$tagId];
    }

    /**
     * @param  list<Business>  $honouredBy
     */
    private function card(int $organizationId, string $name, string $reward, array $honouredBy): LoyaltyCard
    {
        $card = LoyaltyCard::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'stamps_required' => 10,
            'mode' => CardMode::Cyclic,
            'reward_type' => RewardType::Item,
            'reward_text' => $reward,
        ]);
        $card->businesses()->attach(array_map(fn (Business $business): int => $business->id, $honouredBy));

        return $card;
    }

    private function location(Business $business, string $name, string $address): Location
    {
        return Location::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'address' => $address,
            'timezone' => 'Africa/Casablanca',
        ]);
    }

    /** Registers a new tag and assigns it, as the admin action does. */
    private function stamper(Business $business, Location $location, string $label): Stamper
    {
        $tag = (new NfcTag)->forceFill(['uid' => '04'.strtoupper(fake()->unique()->regexify('[0-9A-F]{12}'))]);
        $tag->save();

        $stamper = (new Stamper)->forceFill([
            'business_id' => $business->id,
            'location_id' => $location->id,
            'nfc_tag_id' => $tag->id,
            'label' => $label,
        ]);
        $stamper->save();

        return $stamper;
    }

    private function user(string $name, string $email): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email]);
    }
}
