<?php

declare(strict_types=1);

namespace App\Support\Push;

/**
 * The app's VAPID public key, as a browser subscribes with it (CHW-41), or
 * null when none is set (a local install, a test): then the app offers no
 * notifications at all, rather than an ask that can only fail.
 */
final class PushKey
{
    public static function public(): ?string
    {
        $key = config('webpush.vapid.public_key');

        return is_string($key) && $key !== '' && is_string(config('webpush.vapid.private_key')) ? $key : null;
    }
}
