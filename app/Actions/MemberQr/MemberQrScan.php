<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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

    /** How many windows back a short code reads as late (Expired), not wrong: five minutes of 30 s windows. */
    public const int LATE_WINDOWS = 10;

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
     * one's during the first GRACE_SECONDS (compared in constant time). A code of one of the LATE_WINDOWS before
     * reads as Expired, as a late token does: typed slowly, not guessed. Any other reads as Forged. The same use per
     * customer and scope as the QR's.
     *
     * Six digits can be found by trying: wrong ones are counted per member and scope (WrongCodes), so a hostile
     * business can only lock a member's short codes at its own counter, and after WRONG_CODES in
     * WRONG_CODES_SECONDS that member's short codes are refused there (TooManyAttempts) until the count runs out;
     * their QR still counts. Each staff member's wrong codes are counted too (ScanMemberQr).
     *
     * @throws MemberQrRefused
     */
    public static function fromCode(#[SensitiveParameter] string $code, string $scope): self
    {
        $at = CarbonImmutable::now();
        $fields = MemberQrToken::codeFields($code) ?? throw new MemberQrRefused(MemberQrRefusal::Malformed);

        return WrongCodes::counted(
            'member-qr-code:'.$scope.':'.$fields['userId'],
            self::WRONG_CODES,
            self::WRONG_CODES_SECONDS,
            fn (): self => self::claimed($fields['userId'], self::windowOf($fields, $at), $scope, $at),
            fn (): MemberQrRefused => new MemberQrRefused(MemberQrRefusal::TooManyAttempts),
        );
    }

    /**
     * The window a short code's digits are from, if it still counts: this one, or the previous one in its grace.
     *
     * @param  array{userId: int, digits: string}  $fields
     *
     * @throws MemberQrRefused
     */
    private static function windowOf(array $fields, CarbonImmutable $at): int
    {
        $now = MemberQrToken::windowAt($at);
        $intoWindow = $at->getTimestamp() - $now * MemberQrToken::windowSeconds();

        for ($back = 0; $back <= self::LATE_WINDOWS; $back++) {
            if (hash_equals(MemberQrToken::code($fields['userId'], $now - $back), $fields['digits'])) {
                return $back === 0 || ($back === 1 && $intoWindow < self::GRACE_SECONDS)
                    ? $now - $back
                    : throw new MemberQrRefused(MemberQrRefusal::Expired);
            }
        }

        throw new MemberQrRefused(MemberQrRefusal::Forged);
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
