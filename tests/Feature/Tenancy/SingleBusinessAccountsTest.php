<?php

declare(strict_types=1);

use App\Enums\BusinessStatus;
use App\Enums\OrganizationType;
use App\Models\Business;
use App\Models\Organization;
use App\Support\Tenancy\SingleBusinessAccounts;
use App\Support\Tenancy\TenantContext;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A single business's account (ADR 0006, CHW-22, CHW-315)
|--------------------------------------------------------------------------
|
| An independent café or a one-company chain: not a franchise, and exactly
| one business, archived ones counted. Asked for one organization or for
| several at once (Memberships), the answer is the same.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
});

it('names the organizations that are one business\'s account, and only those', function (): void {
    [$chainOfOne, $chainOfTwo, $withArchived] = $this->context->bypass(function (): array {
        $chainOfOne = Organization::factory()->create(['type' => OrganizationType::Chain]);
        Business::factory()->for($chainOfOne)->create();
        $chainOfTwo = Organization::factory()->create(['type' => OrganizationType::Chain]);
        Business::factory()->for($chainOfTwo)->count(2)->create();
        // An archived business still counts: its co-owner never becomes org admin over another's customers.
        $withArchived = Organization::factory()->create(['type' => OrganizationType::Independent]);
        Business::factory()->for($withArchived)->create();
        Business::factory()->for($withArchived)->create(['archived_at' => now(), 'status' => BusinessStatus::Suspended]);

        return [$chainOfOne, $chainOfTwo, $withArchived];
    });

    $asked = [$this->tenants->orgA->id, $this->tenants->orgB->id, $chainOfOne->id, $chainOfTwo->id, $withArchived->id];

    // The franchise (orgA, two shops) and the chain of two are networks; the café (orgB) and the chain of one are accounts.
    expect(SingleBusinessAccounts::among($asked))->toEqualCanonicalizing([$this->tenants->orgB->id, $chainOfOne->id])
        ->and(array_values(array_filter($asked, SingleBusinessAccounts::isTheAccount(...))))->toEqualCanonicalizing([$this->tenants->orgB->id, $chainOfOne->id])
        ->and(SingleBusinessAccounts::among([]))->toBe([])
        ->and(SingleBusinessAccounts::isTheAccount(null))->toBeFalse();
});
