<?php

declare(strict_types=1);

use App\Actions\Tenancy\CreateIndependentBusiness;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Independent café signup (ADR 0006)
|--------------------------------------------------------------------------
|
| Every business belongs to an organization: signing up an independent café
| creates an `independent` organization with one business. Its owner also
| administers the organization, which owns the card program.
|
*/

it('creates the organization, the business and both memberships', function (): void {
    $owner = User::factory()->create();

    $business = app(CreateIndependentBusiness::class)->handle($owner, 'Café Hafa', 'cafe');

    app(TenantContext::class)->bypass(function () use ($business, $owner): void {
        $organization = $business->organization;

        expect($organization->type)->toBe(OrganizationType::Independent)
            ->and($organization->name)->toBe('Café Hafa')
            ->and($business->status)->toBe(BusinessStatus::Pending)
            ->and($business->plan)->toBeNull()
            ->and($business->category)->toBe('cafe')
            ->and($business->members()->whereKey($owner->id)->first()?->pivot?->role)->toBe(BusinessRole::Owner)
            ->and($business->members()->whereKey($owner->id)->first()?->pivot?->organization_id)->toBe($organization->id)
            ->and($organization->admins()->whereKey($owner->id)->first()?->pivot?->role)->toBe(OrganizationRole::OrgAdmin);
    });
});

it('lands the new owner in their business', function (): void {
    $owner = User::factory()->create();
    $business = app(CreateIndependentBusiness::class)->handle($owner, 'Barbier Nour');

    $this->actingAs($owner);
    Route::middleware(['web', 'tenant'])->get('/_tenant', fn (TenantContext $context): array => [
        'organization' => $context->organizationId(),
        'business' => $context->businessId(),
    ]);

    $this->get('/_tenant')->assertExactJson(Tenants::context($business->organization_id, $business->id));
});

it('gives two businesses with the same name distinct slugs', function (): void {
    $first = app(CreateIndependentBusiness::class)->handle(User::factory()->create(), 'Café Hafa');
    $second = app(CreateIndependentBusiness::class)->handle(User::factory()->create(), 'Café Hafa');

    expect($first->slug)->toBe('cafe-hafa')
        ->and($second->slug)->toBe('cafe-hafa-2')
        ->and($second->organization_id)->not->toBe($first->organization_id);
});

it('retries with a random suffix when a concurrent signup takes the slug', function (): void {
    // Simulate the race: another signup inserts "salon-yasmine" just before ours does.
    Organization::creating(function (): void {
        if (! DB::table('organizations')->where('slug', 'salon-yasmine')->exists()) {
            DB::table('organizations')->insert([
                'name' => 'Salon Yasmine', 'slug' => 'salon-yasmine', 'type' => 'independent',
                'billing_entity' => 'organization', 'white_label' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $business = app(CreateIndependentBusiness::class)->handle(User::factory()->create(), 'Salon Yasmine');

    app(TenantContext::class)->bypass(function () use ($business): void {
        expect($business->organization->slug)->toStartWith('salon-yasmine-')
            ->and($business->organization->slug)->not->toBe('salon-yasmine');
    });
});

it('works while another tenant is current', function (): void {
    $tenants = Tenants::make();
    app(TenantContext::class)->set($tenants->orgA, $tenants->a1);

    $business = app(CreateIndependentBusiness::class)->handle(User::factory()->create(), 'Second café');

    expect($business->organization_id)->not->toBe($tenants->orgA->id);
});
