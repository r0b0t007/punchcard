<?php

declare(strict_types=1);

namespace App\Support\Push;

/**
 * The app's VAPID public key, as a browser subscribes with it (CHW-41), or
 * null when the pair is not set (a local install, a test): then the app
 * offers no notifications at all, rather than an ask that can only fail.
 * The private half is the key itself or a PEM file holding it. No subject
 * needed: the channel falls back to the app's URL.
 */
final class PushKey
{
    public static function public(): ?string
    {
        $key = config('webpush.vapid.public_key');

        return is_string($key) && $key !== '' && (self::filled('webpush.vapid.private_key') || self::filled('webpush.vapid.pem_file')) ? $key : null;
    }

    private static function filled(string $config): bool
    {
        $value = config($config);

        return is_string($value) && $value !== '';
    }
}
