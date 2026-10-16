<?php

declare(strict_types=1);

namespace App\Support\MemberQr;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The member QR's token (CHW-28, C10): PC1.{user}.{window}.{mac}. Who the
 * customer is and which window (punchcard.member_qr.window_seconds) it was
 * shown in, signed: the first 16 bytes of an HMAC-SHA256, base64url. Its key
 * is the app's own (APP_KEY), separated from its other uses; a new APP_KEY
 * simply ends the tokens shown, which last a window anyway. Short, for a
 * dense QR. Anyone can read it; nobody can change it or make one, and each
 * has exactly one spelling. VerifyMemberQr decides whether a scanned one
 * still counts.
 */
final class MemberQrToken
{
    public const string VERSION = 'PC1';

    /** How long one window lasts: a code shows for this long, then the next. */
    public static function windowSeconds(): int
    {
        return max(1, (int) config('punchcard.member_qr.window_seconds'));
    }

    /** The window a moment falls in. */
    public static function windowAt(CarbonInterface $at): int
    {
        return intdiv($at->getTimestamp(), self::windowSeconds());
    }

    /**
     * The user's token now (or at a moment), with its window and the seconds
     * it has left.
     *
     * @return array{token: string, window: int, secondsLeft: int}
     */
    public static function issue(User $user, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $window = self::windowAt($at);
        $userId = (int) $user->id;

        return [
            'token' => self::VERSION.'.'.$userId.'.'.$window.'.'.self::mac($userId, $window),
            'window' => $window,
            'secondsLeft' => ($window + 1) * self::windowSeconds() - $at->getTimestamp(),
        ];
    }

    /**
     * The user and window a token was made for, if it is one and its
     * signature holds (compared in constant time); else null.
     *
     * @return array{userId: int, window: int}|null
     */
    public static function read(string $token): ?array
    {
        $fields = self::fields($token);

        return $fields !== null && hash_equals(self::mac($fields['userId'], $fields['window']), $fields['mac'])
            ? ['userId' => $fields['userId'], 'window' => $fields['window']]
            : null;
    }

    /**
     * A token's parts, if it has a token's shape (the signature unchecked).
     *
     * @return array{userId: int, window: int, mac: string}|null
     */
    public static function fields(string $token): ?array
    {
        // One spelling per token: no leading zeros ("059…" would read as the same window under another hash), and
        // numbers that fit an integer.
        if (preg_match('/^PC1\.([1-9][0-9]{0,17})\.(0|[1-9][0-9]{0,11})\.([A-Za-z0-9_-]{22})$/D', $token, $parts) !== 1) {
            return null;
        }

        return ['userId' => (int) $parts[1], 'window' => (int) $parts[2], 'mac' => $parts[3]];
    }

    private static function mac(int $userId, int $window): string
    {
        $key = hash_hmac('sha256', 'member-qr', (string) config('app.key'), true);
        $mac = substr(hash_hmac('sha256', self::VERSION.'|'.$userId.'|'.$window, $key, true), 0, 16);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
