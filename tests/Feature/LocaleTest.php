<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Mailbox;

describe('locale resolution', function (): void {
    it('defaults to French', function (): void {
        // Symfony's test requests send "en-us,en;q=0.5" unless told otherwise.
        $this->withHeader('Accept-Language', '')
            ->get('/')
            ->assertInertia(fn (Assert $page): Assert => $page->where('locale', 'fr')->where('dir', 'ltr'));
    });

    it('uses the Accept-Language header', function (string $header, string $expected): void {
        $this->withHeader('Accept-Language', $header)
            ->get('/')
            ->assertInertia(fn (Assert $page): Assert => $page->where('locale', $expected));
    })->with([
        'region subtag' => ['en-US,en;q=0.9', 'en'],
        'quality order' => ['de-DE,de;q=0.9,ar;q=0.8,en;q=0.7', 'ar'],
        'unsupported only' => ['de-DE,de;q=0.9', 'fr'],
    ]);

    it('prefers the guest cookie over the header', function (): void {
        $this->withCookie('locale', 'en')
            ->withHeader('Accept-Language', 'ar')
            ->get('/')
            ->assertInertia(fn (Assert $page): Assert => $page->where('locale', 'en'));
    });

    it('ignores an unsupported cookie value', function (): void {
        $this->withCookie('locale', 'xx')
            ->withHeader('Accept-Language', 'en')
            ->get('/')
            ->assertInertia(fn (Assert $page): Assert => $page->where('locale', 'en'));
    });

    it('prefers the user preference over cookie and header', function (): void {
        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user)
            ->withCookie('locale', 'en')
            ->withHeader('Accept-Language', 'en')
            ->get('/')
            ->assertInertia(fn (Assert $page): Assert => $page->where('locale', 'ar')->where('dir', 'rtl'));
    });
});

describe('rendering', function (): void {
    it('sets lang and dir on the html element', function (string $locale, string $dir): void {
        $this->withCookie('locale', $locale)
            ->get('/')
            ->assertSee('<html lang="'.$locale.'" dir="'.$dir.'"', false);
    })->with([
        ['fr', 'ltr'],
        ['en', 'ltr'],
        ['ar', 'rtl'],
    ]);

    it('shares only the active locale translations', function (): void {
        $arabic = json_decode((string) file_get_contents(lang_path('ar.json')), true);

        $this->withCookie('locale', 'ar')
            ->get('/')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('translations', $arabic)
                ->where('translations.Language', 'اللغة')
                ->missing('translations.fr')
                ->missing('translations.ar'));
    });

    it('lists the supported locales with their native names', function (): void {
        $this->get('/')
            ->assertInertia(fn (Assert $page): Assert => $page->where('locales', [
                ['code' => 'fr', 'name' => 'Français'],
                ['code' => 'en', 'name' => 'English'],
                ['code' => 'ar', 'name' => 'العربية'],
            ]));
    });
});

describe('switching locale', function (): void {
    it('stores a guest choice in a cookie', function (): void {
        $this->from('/')
            ->put(route('locale.update'), ['locale' => 'ar'])
            ->assertRedirect('/')
            ->assertCookie('locale', 'ar');
    });

    it('stores a user choice on the account and in a cookie', function (): void {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)
            ->from('/settings/appearance')
            ->put(route('locale.update'), ['locale' => 'en'])
            ->assertRedirect('/settings/appearance')
            ->assertCookie('locale', 'en');

        expect($user->refresh()->locale)->toBe('en');
    });

    it('rejects unsupported locales', function (mixed $locale): void {
        $user = User::factory()->create(['locale' => 'fr']);

        $this->actingAs($user)
            ->from('/')
            ->put(route('locale.update'), ['locale' => $locale])
            ->assertSessionHasErrors('locale')
            ->assertCookieMissing('locale');

        expect($user->refresh()->locale)->toBe('fr');
    })->with(['de', '', 'ar-SA', null]);
});

describe('emails', function (): void {
    it('sends emails in the user\'s saved language, laid out right to left for Arabic', function (): void {
        $user = User::factory()->create(['locale' => 'ar']);

        $this->withCookie('locale', 'fr')->post(route('password.email'), ['email' => $user->email]);
        $email = Mailbox::lastEmail();

        // Gmail and Outlook.com drop <html>/<body> attributes and Outlook ignores
        // text-align: start, so the wrapper and content cell carry dir and alignment.
        expect($email->getSubject())->toBe('أعد تعيين كلمة المرور')
            ->and($email->getHtmlBody())->toContain('lang="ar" dir="rtl"')
            ->toMatch('/<table class="wrapper"[^>]*dir="rtl"/')
            ->toMatch('/<td class="content-cell"(?=[^>]*dir="rtl")(?=[^>]*text-align: right)/')
            ->not->toContain('text-align: left');
    });

    it('falls back to the request language when the user has not chosen one', function (): void {
        $user = User::factory()->create(['locale' => null]);

        $this->withCookie('locale', 'fr')->post(route('password.email'), ['email' => $user->email]);
        $email = Mailbox::lastEmail();

        expect($email->getSubject())->toBe('Réinitialisez votre mot de passe')
            ->and($email->getHtmlBody())->toContain('lang="fr" dir="ltr"')
            ->toMatch('/<td class="content-cell"(?=[^>]*dir="ltr")(?=[^>]*text-align: left)/');
    });

    it('ignores a saved locale that is no longer supported', function (): void {
        $user = User::factory()->create(['locale' => 'de']);

        $this->withCookie('locale', 'fr')->post(route('password.email'), ['email' => $user->email]);

        expect(Mailbox::lastEmail()->getSubject())->toBe('Réinitialisez votre mot de passe');
    });
});
