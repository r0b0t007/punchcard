<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LoginLink;
use App\Models\User;
use App\Notifications\EmailChangedMail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Switches an account to the new address it asked for (CHW-23), from that
 * address's own inbox (a signed link, RequestEmailChange): confirmed at
 * once, and the old address hears of it. Only the address last asked for
 * (FindPendingEmailChange), once, and never one another account took
 * meanwhile. Links and reset tokens mailed to the old address stop working:
 * opened later, a sign-in link would otherwise sign up a new account there.
 */
final readonly class ApplyEmailChange
{
    public const string CHANGED = 'changed';

    public const string EXPIRED = 'expired';

    public const string TAKEN = 'taken';

    public function __construct(private FindPendingEmailChange $findPendingEmailChange) {}

    /** @return self::CHANGED|self::EXPIRED|self::TAKEN */
    public function handle(int $userId, string $hash): string
    {
        try {
            $outcome = DB::transaction(function () use ($userId, $hash): array {
                $user = $this->findPendingEmailChange->handle($userId, $hash, lock: true);

                if (! $user instanceof User) {
                    return [self::EXPIRED, null];
                }

                $pending = (string) $user->pending_email;

                if (User::query()->where('email', $pending)->exists()) {
                    return [self::TAKEN, null];
                }

                $old = $user->email;
                $user->forceFill(['email' => $pending, 'email_verified_at' => now(), 'pending_email' => null, 'pending_email_expires_at' => null])->save();

                LoginLink::query()->where('email', $old)->whereNull('used_at')->update(['used_at' => now()]);
                DB::table('password_reset_tokens')->where('email', $old)->delete();

                return [self::CHANGED, [$user, $old]];
            });
        } catch (UniqueConstraintViolationException) {
            return self::TAKEN;
        }

        [$result, $changed] = $outcome;

        if ($changed !== null) {
            [$user, $old] = $changed;
            Notification::route('mail', $old)->notify((new EmailChangedMail($user->email))->locale($user->preferredLocale() ?? app()->getLocale()));
        }

        return $result;
    }
}
