<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\ConfirmNewEmailMail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * A new address asked for in settings (CHW-23): kept aside (pending_email)
 * and a link mailed to it, which switches the account to it from that
 * inbox (ApplyEmailChange). Until then the account keeps its address, so a
 * mistyped one costs nothing. A later request replaces an earlier one;
 * the current address again cancels it. At most PER_HOUR new addresses an
 * hour per account (each is mailed): false when over.
 */
final readonly class RequestEmailChange
{
    /** How long the link to a new address works. */
    public const int MINUTES = 60;

    /** New addresses an account may ask for in an hour. */
    public const int PER_HOUR = 5;

    public function handle(User $user, string $newEmail): bool
    {
        if ($newEmail === $user->email) {
            $user->forceFill(['pending_email' => null, 'pending_email_expires_at' => null])->save();

            return true;
        }

        if (RateLimiter::tooManyAttempts('email-change:'.$user->id, self::PER_HOUR)) {
            return false;
        }

        RateLimiter::hit('email-change:'.$user->id, 3600);
        $user->forceFill(['pending_email' => $newEmail, 'pending_email_expires_at' => now()->addMinutes(self::MINUTES)])->save();

        $url = rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
            'email-change.show',
            now()->addMinutes(self::MINUTES),
            ['user' => $user->id, 'hash' => sha1($newEmail)],
            absolute: false,
        );

        Notification::route('mail', $newEmail)->notify((new ConfirmNewEmailMail($url))->locale($user->preferredLocale() ?? app()->getLocale()));

        return true;
    }
}
