<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Auth\SetPlatformAdmin;
use App\Actions\Stampers\RegisterStamper;
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
use Carbon\CarbonInterface;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * Demo data for local development and UI work (CHW-21): an independent café
 * (1 business, 2 locations, 1 card, 3 stampers, 20 customers) and a
 * franchise (1 organization, 2 franchisee businesses sharing 1 card), with
 * customers who stamped at one franchisee, the other, or both.
 *
 * It follows the product's rules, as the stamp Actions would have (until it
 * can seed through them, once they exist): writes go through bypass(); the
 * businesses, cards, tags and accounts exist 90 days before any stamp; each
 * member stamps at most once a day (within the cooldown and daily cap),
 * never in the future, and at every place listed for them; tag counters rise
 * with time; each enrollment's counters and rewards come from its stamp
 * events, and redeemed rewards name a business and location of the program.
 * Logins (password "password"): owner@cafe.demo.test, staff@cafe.demo.test,
 * hq@franchise.demo.test, owner@a1.demo.test (Tangier), owner@a2.demo.test
 * (Tétouan), customer@demo.test, a member of both programs, and
 * admin@demo.test, a platform admin (Filament at /admin), whose two-factor
 * code comes from the secret DEMO_ADMIN_TWO_FACTOR_SECRET in an
 * authenticator app, or the recovery code demo-admin-recovery.
 *
 * Known passwords and rows nothing can delete (the ledger, the tags): it runs
 * only in the local and testing environments, in one transaction.
 */
final class DemoSeeder extends Seeder
{
    /** The only environments the demo may be seeded in. */
    public const array ENVIRONMENTS = ['local', 'testing'];

    /** How long the businesses, cards, tags and accounts exist before the first stamp. */
    private const int SETUP_DAYS_AGO = 90;

    /** Repeatable values; seeded only while run() works, then reseeded randomly (Faker seeds mt_rand, which fake() shares). */
    private readonly Generator $faker;

    /** @var array<int, int> Each tag's last SUN counter, by tag id. */
    private array $tagCounters = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CreateIndependentBusiness $createIndependentBusiness,
    ) {
        $this->faker = FakerFactory::create();
    }

    /** Refuses every environment but local and testing: demo accounts have a known password. */
    public static function assertDemoEnvironment(): void
    {
        if (! app()->environment(self::ENVIRONMENTS)) {
            throw new RuntimeException('Demo data has accounts with a known password and rows nothing can delete: it is seeded only in the local and testing environments.');
        }
    }

    public function run(): void
    {
        self::assertDemoEnvironment();

        $this->faker->seed(2026);

        try {
            DB::transaction(fn () => $this->context->bypass(function (): void {
                [$cafe, $franchise] = $this->setUp();

                $this->program(...$cafe);
                $this->program(...$franchise);

                foreach ($this->tagCounters as $tagId => $counter) {
                    NfcTag::query()->whereKey($tagId)->update(['last_counter' => $counter]);
                }
            }));
        } finally {
            $this->faker->seed();
        }
    }

    /**
     * Creates both programs' businesses, cards, tags and accounts as they were
     * SETUP_DAYS_AGO days ago, before any stamp.
     *
     * @return array{0: array{0: LoyaltyCard, 1: list<array{0: User, 1: list<array{0: Business, 1: Stamper, 2: User}>}>}, 1: array{0: LoyaltyCard, 1: list<array{0: User, 1: list<array{0: Business, 1: Stamper, 2: User}>}>}}
     */
    private function setUp(): array
    {
        $realTestNow = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::now()->subDays(self::SETUP_DAYS_AGO));

        try {
            $customer = $this->user('Salma Bennani', 'customer@demo.test');
            app(SetPlatformAdmin::class)->handle($this->admin(), admin: true);

            return [$this->setUpCafe($customer), $this->setUpFranchise($customer)];
        } finally {
            Carbon::setTestNow($realTestNow);
        }
    }

    /**
     * Café Hafa, signed up like any café. Each customer uses one to three of
     * its stampers, served by the owner or the staff member.
     *
     * @return array{0: LoyaltyCard, 1: list<array{0: User, 1: list<array{0: Business, 1: Stamper, 2: User}>}>}
     */
    private function setUpCafe(User $customer): array
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

        $members = [];

        foreach ([$customer, ...$this->users(19)] as $member) {
            $visitedAt = [];
            $first = $this->faker->numberBetween(0, 2);

            foreach (range(0, $this->faker->numberBetween(0, 2)) as $offset) {
                $visitedAt[] = [$business, $stampers[($first + $offset) % 3], $offset % 2 === 0 ? $owner : $staff];
            }

            $members[] = [$member, $visitedAt];
        }

        return [$card, $members];
    }

    /**
     * Atlas Coffee: HQ, two franchisees sharing one card, six customers of each
     * franchisee only and four of both, the demo customer among them.
     *
     * @return array{0: LoyaltyCard, 1: list<array{0: User, 1: list<array{0: Business, 1: Stamper, 2: User}>}>}
     */
    private function setUpFranchise(User $customer): array
    {
        $hq = $this->user('Omar Tazi', 'hq@franchise.demo.test');

        $franchise = new Organization;
        $franchise->forceFill([
            'name' => 'Atlas Coffee', 'slug' => 'atlas-coffee', 'type' => OrganizationType::Franchise, 'brand_color' => '#0F4C81',
        ])->save();
        $franchise->admins()->attach($hq, ['role' => OrganizationRole::OrgAdmin->value]);

        $atTangier = $this->franchisee($franchise, 'Tangier', 'owner@a1.demo.test', 'Nadia Chraibi');
        $atTetouan = $this->franchisee($franchise, 'Tétouan', 'owner@a2.demo.test', 'Hamza Benjelloun');
        $card = $this->card($franchise->id, 'Atlas card', 'Free espresso', [$atTangier[0], $atTetouan[0]]);

        return [$card, [
            ...array_map(fn (User $member): array => [$member, [$atTangier]], $this->users(6)),
            ...array_map(fn (User $member): array => [$member, [$atTetouan]], $this->users(6)),
            ...array_map(fn (User $member): array => [$member, [$atTangier, $atTetouan]], [$customer, ...$this->users(3)]),
        ]];
    }

    /**
     * Enrolls the members and records their visits as stamp events, in time
     * order across the whole program so tag counters rise with time, then
     * sets the counters and rewards those events give. A member stamps at
     * most once a day, from yesterday back (within the cooldown and daily
     * cap); their first visits go once to every place listed for them, the
     * rest anywhere among them.
     *
     * @param  list<array{0: User, 1: list<array{0: Business, 1: Stamper, 2: User}>}>  $members  each member and the places they stamp: business, stamper, served by
     */
    private function program(LoyaltyCard $card, array $members): void
    {
        $visits = [];

        foreach ($members as [$member, $visitedAt]) {
            $days = $this->faker->randomElements(range(1, 60), $this->faker->numberBetween(count($visitedAt), 25));
            rsort($days);
            $times = array_map(fn (mixed $day): CarbonInterface => Carbon::now()->subDays((int) $day)
                ->setTime($this->faker->numberBetween(8, 21), $this->faker->numberBetween(0, 59)), $days);

            $enrollment = CardEnrollment::factory()->create([
                'card_id' => $card->id,
                'user_id' => $member->id,
                'referral_code' => strtoupper($this->faker->unique()->bothify('????####')),
                'created_at' => $times[0],
            ]);

            foreach ($times as $index => $at) {
                $visits[] = [$enrollment, $visitedAt[$index] ?? $visitedAt[$this->faker->numberBetween(0, count($visitedAt) - 1)], $at];
            }
        }

        usort($visits, fn (array $a, array $b): int => $a[2] <=> $b[2]);

        $enrollments = [];
        $eventsByEnrollment = [];

        foreach ($visits as [$enrollment, $place, $at]) {
            $enrollments[$enrollment->id] = $enrollment;
            $eventsByEnrollment[$enrollment->id][] = [$this->stamp($enrollment, $place[0], $place[1], $place[2], $at), $place];
        }

        foreach ($eventsByEnrollment as $id => $events) {
            $this->settle($card, $enrollments[$id], $events);
        }
    }

    /**
     * Sets the enrollment's counters from its events and creates one reward per
     * completed card; all but the latest were redeemed two hours after unlocking.
     * Cyclic cards only: a progressive demo card needs tier milestones.
     *
     * @param  list<array{0: StampEvent, 1: array{0: Business, 1: Stamper, 2: User}}>  $events  in time order
     */
    private function settle(LoyaltyCard $card, CardEnrollment $enrollment, array $events): void
    {
        if ($card->mode !== CardMode::Cyclic) {
            throw new LogicException('The demo seeder settles cyclic cards only.');
        }

        $lifetime = count($events);
        $completed = intdiv($lifetime, $card->stamps_required);

        $enrollment->forceFill([
            'lifetime_stamps' => $lifetime,
            'completed_count' => $completed,
            'current_stamps' => $lifetime % $card->stamps_required,
            'last_stamp_at' => $events[$lifetime - 1][0]->created_at,
        ])->save();

        for ($milestone = 1; $milestone <= $completed; $milestone++) {
            [$event, [$business, $stamper, $staff]] = $events[$milestone * $card->stamps_required - 1];
            $unlockedAt = $event->created_at;
            $redeemed = $milestone < $completed;

            (new Reward)->forceFill([
                'enrollment_id' => $enrollment->id,
                'mode' => $card->mode,
                'milestone' => $milestone,
                'reward_type' => $card->reward_type,
                'reward_value' => $card->reward_value,
                'reward_text' => $card->reward_text,
                'unlocked_at' => $unlockedAt,
                'status' => $redeemed ? RewardStatus::Redeemed : RewardStatus::Available,
                'redeemed_at' => $redeemed ? $unlockedAt->copy()->addHours(2) : null,
                'redeemed_by' => $redeemed ? $staff->id : null,
                'redeemed_business_id' => $redeemed ? $business->id : null,
                'redeemed_location_id' => $redeemed ? $stamper->location_id : null,
                'created_at' => $unlockedAt,
            ])->save();
        }
    }

    /** One stamp: a tap on the stamper (its tag's next counter) or a staff scan of the member QR. */
    private function stamp(CardEnrollment $enrollment, Business $business, Stamper $stamper, User $staff, CarbonInterface $at): StampEvent
    {
        $tap = $this->faker->boolean(70);

        return StampEvent::factory()->create([
            'enrollment_id' => $enrollment->id,
            'business_id' => $business->id,
            'location_id' => $stamper->location_id,
            'source' => $tap ? StampSource::Nfc : StampSource::Qr,
            'stamper_id' => $tap ? $stamper->id : null,
            'nfc_tag_id' => $tap ? $stamper->nfc_tag_id : null,
            'counter' => $tap ? $this->nextCounter($stamper->nfc_tag_id) : null,
            'staff_id' => $tap ? null : $staff->id,
            'idempotency_key' => $tap ? null : (string) Str::uuid(),
            'created_at' => $at,
        ]);
    }

    /** Taps skip a few counter values (test taps, rejected ones), as real tags do. */
    private function nextCounter(int $tagId): int
    {
        $this->tagCounters[$tagId] = ($this->tagCounters[$tagId] ?? 0) + $this->faker->numberBetween(1, 3);

        return $this->tagCounters[$tagId];
    }

    /**
     * A franchisee: its business, owner, one location and one stamper.
     *
     * @return array{0: Business, 1: Stamper, 2: User}
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

        return [$business, $this->stamper($business, $location, 'Counter'), $owner];
    }

    /**
     * @param  list<Business>  $honouredBy
     */
    private function card(int $organizationId, string $name, string $reward, array $honouredBy): LoyaltyCard
    {
        $card = LoyaltyCard::factory()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'stamps_required' => 10,
            'mode' => CardMode::Cyclic,
            'reward_type' => RewardType::Item,
            'reward_text' => $reward,
        ]);
        $card->businesses()->attach(array_map(fn (Business $business): int => $business->id, $honouredBy));

        return $card->refresh();
    }

    private function location(Business $business, string $name, string $address): Location
    {
        return Location::factory()->create([
            'business_id' => $business->id,
            'name' => $name,
            'address' => $address,
            'timezone' => 'Africa/Casablanca',
        ]);
    }

    /** Registers a new tag and assigns it, through the platform admin's Action. */
    private function stamper(Business $business, Location $location, string $label): Stamper
    {
        return app(RegisterStamper::class)->handle('04'.strtoupper($this->faker->unique()->regexify('[0-9A-F]{12}')), $business, $location, $label);
    }

    /** A platform admin needs two-factor authentication: a known secret, as the demo's passwords are known. */
    private const string DEMO_ADMIN_TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';

    private function admin(): User
    {
        return User::factory()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@demo.test',
            'two_factor_secret' => encrypt(self::DEMO_ADMIN_TWO_FACTOR_SECRET),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['demo-admin-recovery'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function user(string $name, string $email): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email]);
    }

    /**
     * @return list<User>
     */
    private function users(int $count): array
    {
        return array_map(fn (): User => $this->user($this->faker->name(), $this->faker->unique()->safeEmail()), range(1, $count));
    }
}
