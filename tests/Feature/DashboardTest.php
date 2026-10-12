<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Tenants;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_whoever_works_in_a_business_can_visit_the_dashboard(): void
    {
        $tenants = Tenants::make();
        $user = $tenants->member(User::factory()->create(), $tenants->b1, BusinessRole::Owner);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_customers_are_sent_to_their_cards(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('app.cards'));
    }
}
