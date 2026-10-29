<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'adult' => '1',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('start', absolute: false));
    }

    public function test_new_users_keep_the_language_they_registered_in(): void
    {
        // CHW-132: the page was in French, so later emails are too.
        $this->withHeader('Accept-Language', 'fr')->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'adult' => '1',
        ])->assertRedirect(route('start', absolute: false));

        $this->assertSame('fr', User::query()->where('email', 'test@example.com')->sole()->locale);
    }
}
