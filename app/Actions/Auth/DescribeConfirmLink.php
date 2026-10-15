<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LoginLink;
use App\Models\User;

/**
 * What a "confirm it's you" link (CHW-23) is to the signed-in account that
 * opened it, spending nothing: ready to use (ConfirmByLink), another
 * account's, or out of date (used, too old, or for an account that may not
 * confirm by email). The caller has checked the URL's signature.
 */
final readonly class DescribeConfirmLink
{
    public const string READY = 'ready';

    public const string EXPIRED = 'expired';

    public const string OTHER_ACCOUNT = 'other-account';

    public function __construct(private MayConfirmByEmail $mayConfirmByEmail) {}

    /** @return self::READY|self::EXPIRED|self::OTHER_ACCOUNT */
    public function handle(User $user, int $linkId): string
    {
        $mailedTo = LoginLink::query()->whereKey($linkId)->usable(LoginLink::CONFIRM)->value('email');

        return match (true) {
            ! is_string($mailedTo) || ! $this->mayConfirmByEmail->handle($user) => self::EXPIRED,
            $mailedTo !== $user->email => self::OTHER_ACCOUNT,
            default => self::READY,
        };
    }
}
