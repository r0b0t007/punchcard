<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\EmailChangedMail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Switches an account to the new address it asked for (CHW-23), from that
 * address's own inbox (a signed link, RequestEmailChange): confirmed at
 * once, and the old address hears of it. Only the address last asked for
 * (the link carries its hash), once, and never one another account took
 * meanwhile.
 */
final readonly class ApplyEmailChange
{
    public const string CHANGED = 'changed';

    public const string EXPIRED = 'expired';

    public const string TAKEN = 'taken';

    /** @return self::CHANGED|self::EXPIRED|self::TAKEN */
    public function handle(int $userId, string $hash): string
    {
        try {
            $outcome = DB::transaction(function () use ($userId, $hash): array {
                $user = User::query()->lockForUpdate()->find($userId);

                if (! $user instanceof User || $user->pending_email === null || ! hash_equals(sha1($user->pending_email), $hash)) {
                    return [self::EXPIRED, null];
                }

                if (User::query()->where('email', $user->pending_email)->exists()) {
                    return [self::TAKEN, null];
                }

                $old = $user->email;
                $user->forceFill(['email' => $user->pending_email, 'email_verified_at' => now(), 'pending_email' => null])->save();

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
