<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\OrganizationType;
use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;

/**
 * Two tenant boundaries for isolation tests (ADR 0006): organization A is a
 * franchise with franchisees A1 and A2; organization B has one business B1.
 * Every business has one location. Built inside bypass(), like a seeder.
 */
final readonly class Tenants
{
    private function __construct(
        public Organization $orgA,
        public Business $a1,
        public Business $a2,
        public Organization $orgB,
        public Business $b1,
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

            return new self($orgA, $a1, $a2, $orgB, $b1);
        });
    }

    /** The location of a business, read inside bypass(). */
    public function locationOf(Business $business): Location
    {
        return app(TenantContext::class)->bypass(
            fn (): Location => Location::query()->where('business_id', $business->id)->firstOrFail(),
        );
    }
}
