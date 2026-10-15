<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Auth\MailedLink;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
     * Links that can still be spent for a purpose: unused and in date.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usable(Builder $query, string $purpose): void
    {
        $query->where('purpose', $purpose)->whereNull('used_at')->where('expires_at', '>', now());
    }

    /** Its URL, signed until it expires: what the mail links to (MailedLink::signed). */
    public function signedUrl(): string
    {
        $route = $this->purpose === self::CONFIRM ? 'password.confirm-by-email.show' : 'login-link.show';

        return MailedLink::signed($route, $this->expires_at, ['link' => $this->id]);
    }

    /** Its address, half hidden, for the link's page: s***@example.com. */
    public function maskedEmail(): string
    {
        return MailedLink::maskedEmail($this->email);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
