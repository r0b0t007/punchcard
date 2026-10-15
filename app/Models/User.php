<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlatformRole;
use App\Support\Locales;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $locale
 * @property Carbon|null $email_verified_at
 * @property string|null $password null for a customer signed up by email link (CHW-23)
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $anonymised_at
 * @property Carbon|null $adult_confirmed_at when they said they are 16 or older (CHW-23)
 * @property string|null $pending_email a new address asked for in settings, until confirmed from its inbox (CHW-23)
 * @property Carbon|null $marketing_opt_in_at when they asked for news by email; null: never asked for (CHW-23)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The Filament panel at /admin is for platform admins only (CHW-22): a
     * verified email and two-factor authentication confirmed, which Fortify's
     * sign-in (the panel has none of its own) then always asks for. An
     * anonymised account is never one. Business owners and staff use the app,
     * never the panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && ! $this->isAnonymised()
            && $this->hasVerifiedEmail()
            && $this->two_factor_confirmed_at !== null
            && $this->hasRole(PlatformRole::Admin->value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'adult_confirmed_at' => 'datetime',
            'marketing_opt_in_at' => 'datetime',
        ];
    }

    /**
     * An address as accounts store it and every lookup uses (sign-in, email
     * links, their limits, CHW-23): trimmed, and in lower case when Fortify
     * lowercases usernames (fortify.lowercase_usernames).
     */
    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);

        return config('fortify.lowercase_usernames') ? Str::lower($email) : $email;
    }

    /**
     * Addresses are stored normalised, however they are typed (sign-up,
     * settings, admin).
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normalizeEmail($value));
    }

    /** Signed up by email link, an account has none until it chooses one (CHW-23). */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * An account without a password (signed up by email link, CHW-23) has
     * none to match: never a null hash, which the remember cookie and
     * AuthenticateSession would otherwise hash.
     */
    public function getAuthPassword(): string
    {
        return $this->password ?? '';
    }

    /** The account was deleted but its stamp history kept (DeleteAccount): no person behind it any more. */
    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    /**
     * People still behind their account: what campaigns, exports and other
     * fan-outs to users start from.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notAnonymised(Builder $query): void
    {
        $query->whereNull('anonymised_at');
    }

    /** No mail to an anonymised account: there is nobody to receive it. */
    public function routeNotificationForMail(): ?string
    {
        return $this->isAnonymised() ? null : $this->email;
    }

    /**
     * Notifications (password reset, email verification...) go out in the
     * user's saved language; null falls back to the current request's locale.
     */
    public function preferredLocale(): ?string
    {
        return Locales::isSupported($this->locale) ? $this->locale : null;
    }
}
