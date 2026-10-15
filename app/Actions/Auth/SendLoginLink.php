<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LoginLink;
use App\Models\User;
use App\Notifications\LoginLinkMail;
use App\Notifications\SignInWithPasswordMail;
use Illuminate\Support\Facades\Notification;

/**
 * Emails a sign-in link (CHW-23) to a customer's account or a new address:
 * a LoginLink row, and its URL signed until it expires. An account that may
 * not use links (MaySignInByLink) is told to use its password instead; an
 * anonymised one gets nothing. The caller answers the same in every case.
 * In the account's language, else the page's. Run on the queue
 * (MailLoginLink), so the page answers in the same time for any address.
 */
final readonly class SendLoginLink
{
    public function __construct(private MaySignInByLink $maySignInByLink) {}

    public function handle(string $email, string $pageLocale): void
    {
        $user = User::query()->where('email', $email)->first();
        $locale = $user?->preferredLocale() ?? $pageLocale;

        if ($user instanceof User && ! $this->maySignInByLink->handle($user)) {
            if (! $user->isAnonymised()) {
                $user->notify((new SignInWithPasswordMail)->locale($locale));
            }

            return;
        }

        $mail = (new LoginLinkMail(LoginLink::for($email)->signedUrl()))->locale($locale);

        if ($user instanceof User) {
            $user->notify($mail);
        } else {
            Notification::route('mail', $email)->notify($mail);
        }
    }
}
