<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LoginLink;
use App\Models\User;
use App\Notifications\ConfirmLinkMail;

/**
 * Mails a "confirm it's you" link to an account's own address (CHW-23), if
 * it may confirm so (MayConfirmByEmail). Whether one was sent.
 */
final readonly class SendConfirmLink
{
    public function __construct(private MayConfirmByEmail $mayConfirmByEmail) {}

    public function handle(User $user): bool
    {
        if (! $this->mayConfirmByEmail->handle($user)) {
            return false;
        }

        $user->notify(new ConfirmLinkMail(LoginLink::for($user->email, LoginLink::CONFIRM)->signedUrl()));

        return true;
    }
}
