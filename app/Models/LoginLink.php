<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * An email sign-in link (CHW-23): its URL is signed and good until
 * expires_at; used_at makes it good once (UseLoginLink). The address is
 * personal data: rows go a day after they were sent (model:prune).
 *
 * @property int $id
 * @property string $email
 * @property string $purpose self::SIGN_IN or self::CONFIRM
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 */
#[Fillable(['email', 'expires_at', 'purpose'])]
class LoginLink extends Model
{
    use MassPrunable;

    public const null UPDATED_AT = null;

    /** How long a link signs in. */
    public const int MINUTES = 15;

    /** A link that signs a customer in (LoginLinkController). */
    public const string SIGN_IN = 'sign-in';

    /** A link that confirms it's them, to a signed-in account without a password (ConfirmByEmailController). */
    public const string CONFIRM = 'confirm';

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDay());
    }

    /** A new link for an address, good for MINUTES. */
    public static function for(string $email, string $purpose = self::SIGN_IN): self
    {
        return static::query()->create(['email' => $email, 'expires_at' => now()->addMinutes(self::MINUTES), 'purpose' => $purpose]);
    }

    /**
     * Spends a link, once and only for its purpose: one UPDATE marks it used
     * only if it is unused, in date and for that purpose, so of two taps on
     * it only one counts, on any database, with no lock held. Given an
     * address, only a link mailed to it is spent (anyone else leaves it to
     * its owner). Its address, or null when it can't be spent.
     */
    public static function spend(int $id, string $purpose, ?string $email = null): ?string
    {
        $spent = static::query()
            ->whereKey($id)
            ->where('purpose', $purpose)
            ->when($email !== null, fn (Builder $query): Builder => $query->where('email', $email))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        $email = $spent === 1 ? static::query()->whereKey($id)->value('email') : null;

        return is_string($email) ? $email : null;
    }

    /** The link, if it can still be spent for that purpose: for its page, which spends nothing. */
    public static function usable(int $id, string $purpose): ?self
    {
        $link = static::query()->whereKey($id)->where('purpose', $purpose)->first();

        return $link?->isUsable() === true ? $link : null;
    }

    /**
     * Its URL, signed until it expires: what the mail links to. Always on
     * the app's own address (app.url), never the request's host, which a
     * client can name: a link mailed to someone else's host would hand them
     * its signature. The signature leaves the host out (LoginLinkController
     * checks it the same way), so it also works behind a proxy.
     */
    public function signedUrl(): string
    {
        $route = $this->purpose === self::CONFIRM ? 'password.confirm-by-email.show' : 'login-link.show';

        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute($route, $this->expires_at, ['link' => $this->id], absolute: false);
    }

    /** Its address, half hidden, for the link's page: s***@example.com. */
    public function maskedEmail(): string
    {
        [$name, $domain] = array_pad(explode('@', $this->email, 2), 2, '');

        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
