<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\BusinessRole;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\Reward;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Two tenant boundaries for isolation tests (ADR 0006): organization A is a
 * franchise with franchisees A1 and A2; organization B has one business B1.
 * Every business has one location. A runs one card that A1 and A2 both
 * honour; B has its own card. Built inside bypass(), like a seeder.
 */
final readonly class Tenants
{
    private function __construct(
        public Organization $orgA,
        public Business $a1,
        public Business $a2,
        public Organization $orgB,
        public Business $b1,
        public LoyaltyCard $cardA,
        public LoyaltyCard $cardB,
    ) {}

    public static function make(): self
    {
        return app(TenantContext::class)->bypass(function (): self {
            $orgA = Organization::factory()->create(['type' => OrganizationType::Franchise]);
            $orgB = Organization::factory()->create();

            $a1 = Business::factory()->for($orgA)->create(['name' => 'A1']);
            $a2 = Business::factory()->for($orgA)->create(['name' => 'A2']);
            $b1 = Business::factory()->for($orgB)->create(['name' => 'B1']);

            foreach ([$a1, $a2, $b1] as $business) {
                Location::factory()->for($business)->create(['name' => $business->name.' site']);
            }

            $cardA = LoyaltyCard::factory()->for($orgA)->create(['name' => 'A card']);
            $cardA->businesses()->attach([$a1->id, $a2->id]);
            $cardB = LoyaltyCard::factory()->for($orgB)->create(['name' => 'B card']);
            $cardB->businesses()->attach([$b1->id]);

            return new self($orgA, $a1, $a2, $orgB, $b1, $cardA, $cardB);
        });
    }

    /** Makes the user an owner or staff member of the business, inside bypass(). */
    public function member(User $user, Business $business, BusinessRole $role = BusinessRole::Staff): User
    {
        app(TenantContext::class)->bypass(fn () => $business->members()->attach($user, ['role' => $role->value]));

        return $user;
    }

    /** Makes the user an org admin of the organization, inside bypass(). */
    public function admin(User $user, Organization $organization): User
    {
        app(TenantContext::class)->bypass(fn () => $organization->admins()->attach($user, ['role' => OrganizationRole::OrgAdmin->value]));

        return $user;
    }

    /** Enrolls the customer on the card, inside bypass(). */
    public function enroll(User $customer, LoyaltyCard $card): CardEnrollment
    {
        return app(TenantContext::class)->bypass(fn (): CardEnrollment => CardEnrollment::factory()->for($card, 'card')->for($customer)->create());
    }

    /** Unlocks the enrollment's first reward (milestone 1), inside bypass(). */
    public function reward(CardEnrollment $enrollment): Reward
    {
        return app(TenantContext::class)->bypass(fn (): Reward => Reward::factory()->for($enrollment, 'enrollment')->create());
    }

    /** Registers a stamper at the business's location, inside bypass(), as an admin would. */
    public function stamper(Business $business): Stamper
    {
        $location = $this->locationOf($business);

        return app(TenantContext::class)->bypass(fn (): Stamper => Stamper::factory()->create([
            'business_id' => $business->id,
            'location_id' => $location->id,
        ]));
    }

    /**
     * Records a stamp (one QR stamp unless overridden) for the enrollment at the
     * business's location, inside bypass(), as the stamp Actions do. Staff
     * sources get a staff member and an idempotency key unless given (null
     * included). source is set raw, so a test can also try a value the enum
     * does not know.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function stamp(CardEnrollment $enrollment, Business $business, array $overrides = []): StampEvent
    {
        $location = $this->locationOf($business);

        return app(TenantContext::class)->bypass(function () use ($enrollment, $business, $location, $overrides): StampEvent {
            $source = $overrides['source'] ?? StampSource::Qr;
            unset($overrides['source']);

            if (in_array($source, [StampSource::Qr, StampSource::Manual, StampSource::Correction], true)) {
                $overrides += ['staff_id' => User::factory()->create()->id, 'idempotency_key' => (string) Str::uuid()];
            }

            $event = (new StampEvent)->forceFill([
                'enrollment_id' => $enrollment->id,
                'business_id' => $business->id,
                'location_id' => $location->id,
                'qty' => 1,
                ...$overrides,
            ]);
            $event->setRawAttributes([...$event->getAttributes(), 'source' => $source instanceof StampSource ? $source->value : $source]);
            $event->save();

            return $event;
        });
    }

    /** Registers /_tenant, which returns the TenantContext the `tenant` middleware set. */
    public static function probeRoute(): void
    {
        Route::middleware(['web', 'tenant'])->get('/_tenant', fn (TenantContext $context): array => [
            'organization' => $context->organizationId(),
            'business' => $context->businessId(),
            'org_admin' => $context->isOrgAdmin(),
            'role' => $context->businessRole()?->value,
        ]);
    }

    /**
     * The TenantContext as /_tenant returns it.
     *
     * @return array{organization: int|null, business: int|null, org_admin: bool, role: string|null}
     */
    public static function context(?int $organization, ?int $business = null, bool $orgAdmin = false, ?BusinessRole $role = null): array
    {
        return ['organization' => $organization, 'business' => $business, 'org_admin' => $orgAdmin, 'role' => $role?->value];
    }

    /** The location of a business, read inside bypass(). */
    public function locationOf(Business $business): Location
    {
        return app(TenantContext::class)->bypass(
            fn (): Location => Location::query()->where('business_id', $business->id)->firstOrFail(),
        );
    }
}
