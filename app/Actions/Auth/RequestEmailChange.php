<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\ConfirmNewEmailMail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * A new address asked for in settings (CHW-23): kept aside (pending_email)
 * and a link mailed to it, which switches the account to it from that
 * inbox (ApplyEmailChange). Until then the account keeps its address, so a
 * mistyped one costs nothing. A later request replaces an earlier one.
 */
final readonly class RequestEmailChange
{
    /** How long the link to a new address works. */
    public const int MINUTES = 60;

    public function handle(User $user, string $newEmail): void
    {
        $user->forceFill(['pending_email' => $newEmail])->save();

        $url = rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
            'email-change.show',
            now()->addMinutes(self::MINUTES),
            ['user' => $user->id, 'hash' => sha1($newEmail)],
            absolute: false,
        );

        Notification::route('mail', $newEmail)->notify((new ConfirmNewEmailMail($url))->locale($user->preferredLocale() ?? app()->getLocale()));
    }
}
