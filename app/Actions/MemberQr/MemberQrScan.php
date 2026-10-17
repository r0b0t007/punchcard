<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;

/**
 * A member QR that counts (CHW-28): whose it is, the window it was shown
 * in, and when it was scanned, for the stamp or redemption it proves
 * presence for (CHW-30). The only ways to get one are fromToken() and
 * fromCode() (the short code shown with it), which check what was scanned or
 * typed: there is no other way in, so no code can make a scan out of a
 * request's values, only out of a real member QR.
 *
 * It counts when it is a real token (MemberQrToken) of a customer whose
 * account still exists, from the current window, or the previous one
 * during the first GRACE_SECONDS of this one (a code scanned as it
 * changed): never more than window + grace old. Used once per customer and
 * scope while a code of theirs can count: the caller names the scope (the
 * card it stamps on, the reward it redeems), so a second scan for it, of
 * this code or of the next one shown meanwhile (a screenshot passed on, a
 * double scan), is refused. The use is kept in the cache until then.
 * Always by the server's clock. VerifyMemberQr is the Action callers use.
 */
final readonly class MemberQrScan
{
    /** How long into a window the previous window's code still counts. */
    public const int GRACE_SECONDS = 10;

    /** Wrong short codes typed for one member before their short codes are refused for a while. */
    public const int WRONG_CODES = 5;

    /** How long wrong short codes count against a member. */
    public const int WRONG_CODES_SECONDS = 600;

    private function __construct(
        public int $userId,
        public int $window,
        public CarbonImmutable $scannedAt,
    ) {}

    /**
     * How long a use is remembered: two windows and the grace, the longest any code of the customer's shown by
     * then can count, and a little slack.
     */
    public static function rememberedSeconds(): int
    {
        return 2 * MemberQrToken::windowSeconds() + self::GRACE_SECONDS + 5;
    }

    /**
     * The scan of a token, if it counts for this scope; else MemberQrRefused, saying why.
     *
     * @throws MemberQrRefused
     */
    public static function fromToken(#[SensitiveParameter] string $token, string $scope): self
    {
        $at = CarbonImmutable::now();
        $fields = MemberQrToken::fields($token) ?? throw new MemberQrRefused(MemberQrRefusal::Malformed);

        if (! MemberQrToken::authentic($fields)) {
            throw new MemberQrRefused(MemberQrRefusal::Forged);
        }

        $now = MemberQrToken::windowAt($at);
        $intoWindow = $at->getTimestamp() - $now * MemberQrToken::windowSeconds();
        $current = $fields['window'] === $now;
        $justBefore = $fields['window'] === $now - 1 && $intoWindow < self::GRACE_SECONDS;

        if (! $current && ! $justBefore) {
            throw new MemberQrRefused(MemberQrRefusal::Expired);
        }

        return self::claimed($fields['userId'], $fields['window'], $scope, $at);
    }

    /**
     * The scan of a short code typed in place of the QR (CHW-30), if it counts for this scope; else
     * MemberQrRefused, saying why. The same windows as a token's: its digits are this window's, or the previous
     * one's during the first GRACE_SECONDS (compared in constant time). An older code reads as Forged: its digits
     * prove nothing else. The same use per customer and scope as the QR's. Six digits can be found by trying, from
     * any number of staff accounts: wrong ones are counted per member, wherever typed, and after WRONG_CODES in
     * WRONG_CODES_SECONDS that member's short codes are refused (TooManyAttempts) until the count runs out; their
     * QR still counts.
     *
     * @throws MemberQrRefused
     */
    public static function fromCode(#[SensitiveParameter] string $code, string $scope): self
    {
        $at = CarbonImmutable::now();
        $fields = MemberQrToken::codeFields($code) ?? throw new MemberQrRefused(MemberQrRefusal::Malformed);
        $attempts = 'member-qr-code:'.$fields['userId'];

        // Counted before the digits are checked, so a burst of guesses can't all pass the limit at once; a right
        // code gives its attempt back.
        if (RateLimiter::hit($attempts, self::WRONG_CODES_SECONDS) > self::WRONG_CODES) {
            throw new MemberQrRefused(MemberQrRefusal::TooManyAttempts);
        }

        $now = MemberQrToken::windowAt($at);
        $intoWindow = $at->getTimestamp() - $now * MemberQrToken::windowSeconds();

        $window = match (true) {
            hash_equals(MemberQrToken::code($fields['userId'], $now), $fields['digits']) => $now,
            $intoWindow < self::GRACE_SECONDS && hash_equals(MemberQrToken::code($fields['userId'], $now - 1), $fields['digits']) => $now - 1,
            default => null,
        };

        if ($window === null) {
            throw new MemberQrRefused(MemberQrRefusal::Forged);
        }

        RateLimiter::decrement($attempts, self::WRONG_CODES_SECONDS);

        return self::claimed($fields['userId'], $window, $scope, $at);
    }

    /**
     * A checked code of a customer whose account still exists, used for this scope.
     *
     * @throws MemberQrRefused
     */
    private static function claimed(int $userId, int $window, string $scope, CarbonImmutable $at): self
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User || $user->isAnonymised()) {
            throw new MemberQrRefused(MemberQrRefusal::UnknownUser);
        }

        // Kept while any code of theirs shown by then could still count: the next window's code lasts until two
        // windows and a grace from the start of this one, at the latest.
        if (! Cache::add('member-qr:'.$userId.':'.$scope, true, $at->addSeconds(self::rememberedSeconds()))) {
            throw new MemberQrRefused(MemberQrRefusal::Replayed);
        }

        return new self($userId, $window, $at);
    }
}
