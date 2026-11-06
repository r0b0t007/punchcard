<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('start', absolute: false));
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited(): void
    {
        $user = User::factory()->create();

        RateLimiter::increment($this->throttleKey($user), amount: 5);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }

    public function test_the_rate_limit_follows_its_setting(): void
    {
        $user = User::factory()->create();
        config(['punchcard.auth.login_per_minute' => 8]);
        $attempt = fn () => $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

        // Five attempts so far, the default's limit: the sixth still goes through under a limit of 8.
        RateLimiter::increment($this->throttleKey($user), amount: 5);
        $attempt()->assertSessionHasErrors('email');

        RateLimiter::increment($this->throttleKey($user), amount: 2);
        $attempt()->assertTooManyRequests();
    }

    public function test_a_blank_zero_or_mistyped_limit_is_the_default_never_none(): void
    {
        $read = function (string $value): mixed {
            $_ENV['LOGIN_PER_MINUTE'] = $_SERVER['LOGIN_PER_MINUTE'] = $value;

            try {
                return (require config_path('punchcard.php'))['auth']['login_per_minute'];
            } finally {
                unset($_ENV['LOGIN_PER_MINUTE'], $_SERVER['LOGIN_PER_MINUTE']);
            }
        };

        foreach (['', '0', '-3', '60/min'] as $value) {
            $this->assertSame(5, $read($value), "LOGIN_PER_MINUTE={$value}");
        }

        $this->assertSame(60, $read('60'));
    }

    /** Fortify's login throttle key for a user signing in from the test's address, as its limiter hashes it. */
    private function throttleKey(User $user): string
    {
        return md5('login'.implode('|', [$user->email, '127.0.0.1']));
    }
}
