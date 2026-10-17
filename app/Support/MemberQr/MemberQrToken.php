<?php

declare(strict_types=1);

namespace App\Support\MemberQr;

use App\Models\User;
use Carbon\CarbonInterface;
use SensitiveParameter;

/**
 * The member QR's token (CHW-28, C10): PC1.{user}.{window}.{mac}. Who the
 * customer is and which window (punchcard.member_qr.window_seconds) it was
 * shown in, signed: the first 16 bytes of an HMAC-SHA256, base64url. Its key
 * is the app's own (APP_KEY), separated from its other uses; a new APP_KEY
 * simply ends the tokens shown, which last a window anyway. Short, for a
 * dense QR. Anyone can read it; nobody can change it or make one, and each
 * has exactly one spelling. VerifyMemberQr decides whether a scanned one
 * still counts.
 *
 * Shown with it, a short code to type when a camera cannot scan it
 * (CHW-30): the member number and six digits (CODE_DIGITS) of an HMAC with
 * a key of its own, for the same window. Six digits are found by trying,
 * not computed: wrong ones are counted per member (MemberQrScan::fromCode),
 * and a code counts for a window.
 */
final class MemberQrToken
{
    public const string VERSION = 'PC1';

    /** How many digits a short code has after the member number. */
    public const int CODE_DIGITS = 6;

    /** The longest input read as a short code, spaces included. */
    public const int MAX_TYPED = 40;

    /** Arabic-Indic and Persian digits, read as ASCII ones. */
    private const array LOCAL_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

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
     * Whether a token's parts carry its own signature (compared in constant time).
     *
     * @param  array{userId: int, window: int, mac: string}  $fields
     */
    public static function authentic(array $fields): bool
    {
        return hash_equals(self::mac($fields['userId'], $fields['window']), $fields['mac']);
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

    /** The user's short code now (or at a moment), to type: "{member} {digits}". */
    public static function shortCode(User $user, ?CarbonInterface $at = null): string
    {
        $userId = (int) $user->id;

        return $userId.' '.self::code($userId, self::windowAt($at ?? now()));
    }

    /** The digits of a member's short code in a window. */
    public static function code(int $userId, int $window): string
    {
        $key = hash_hmac('sha256', 'member-qr-code', (string) config('app.key'), true);
        $value = unpack('N', hash_hmac('sha256', self::VERSION.'C|'.$userId.'|'.$window, $key, true))[1] ?? 0;

        return str_pad((string) (($value & 0x7FFFFFFF) % 10 ** self::CODE_DIGITS), self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * A typed short code's parts, if it has one's shape: the member number (no leading zero), then the six
     * digits. A space, dot, dash or middle dot after the number ends it, and the same may group the digits;
     * typed with none, the last six digits are the code. Arabic-Indic and Persian digits (an Arabic keyboard's)
     * read as theirs, any space (no-break ones too) or Arabic separator as a gap, and invisible marks (bidi, from a
     * copy) are dropped; anything longer than MAX_TYPED characters is not a code. The digits unchecked.
     *
     * @return array{userId: int, digits: string}|null
     */
    public static function codeFields(#[SensitiveParameter] string $typed): ?array
    {
        if (mb_strlen($typed) > self::MAX_TYPED) {
            return null;
        }

        $typed = strtr((string) preg_replace(['/\p{Cf}+/u', '/^\s+|\s+$/u'], '', $typed), self::LOCAL_DIGITS);
        $gap = '[\s.\-\x{00B7}\x{066B}\x{066C}\x{060C}]';

        if (preg_match('/^([1-9][0-9]{0,17})(?:'.$gap.'+([0-9](?:[0-9]|'.$gap.')*)|([0-9]{'.self::CODE_DIGITS.'}))$/uD', $typed, $parts) !== 1) {
            return null;
        }

        $digits = ($parts[2] ?? '') !== '' ? (string) preg_replace('/\D+/', '', $parts[2]) : ($parts[3] ?? '');

        return strlen($digits) === self::CODE_DIGITS ? ['userId' => (int) $parts[1], 'digits' => $digits] : null;
    }

    /**
     * The member number a scanned token or typed short code names, unchecked; null if it is neither. Trimmed as
     * VerifyMemberQr trims it, so both read the same member.
     */
    public static function memberOf(#[SensitiveParameter] string $scanned): ?int
    {
        $scanned = trim($scanned);

        return (self::fields($scanned) ?? self::codeFields($scanned))['userId'] ?? null;
    }

    private static function mac(int $userId, int $window): string
    {
        $key = hash_hmac('sha256', 'member-qr', (string) config('app.key'), true);
        $mac = substr(hash_hmac('sha256', self::VERSION.'|'.$userId.'|'.$window, $key, true), 0, 16);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
