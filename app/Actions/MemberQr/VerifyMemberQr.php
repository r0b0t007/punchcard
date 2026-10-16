<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;

/**
 * Whether a scanned member QR counts (CHW-28), for staff scanning (CHW-30):
 * a real token (MemberQrToken) of a customer whose account still exists,
 * from the current window, or the previous one during the first
 * GRACE_SECONDS of this one (a code scanned as it changed): never more than
 * window + grace old. Used once per customer and scope while a code of
 * theirs can count: the caller names the scope (the card it stamps on, the
 * reward it redeems), so a second scan for it, of this code or of the next
 * one shown meanwhile (a screenshot passed on, a double scan), is refused.
 * The use is kept in the cache until then. Always by the server's clock.
 */
final readonly class VerifyMemberQr
{
    /** How long into a window the previous window's code still counts. */
    public const int GRACE_SECONDS = 10;

    /**
     * How long a use is remembered: two windows and the grace, the longest any code of the customer's shown by
     * then can count, and a little slack.
     */
    public static function rememberedSeconds(): int
    {
        return 2 * MemberQrToken::windowSeconds() + self::GRACE_SECONDS + 5;
    }

    public function handle(#[SensitiveParameter] string $token, string $scope): MemberQrScan
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

        $user = User::query()->find($fields['userId']);

        if (! $user instanceof User || $user->isAnonymised()) {
            throw new MemberQrRefused(MemberQrRefusal::UnknownUser);
        }

        // Kept while any code of theirs shown by then could still count: the next window's code lasts until two
        // windows and a grace from the start of this one, at the latest.
        $expires = $at->addSeconds(self::rememberedSeconds());

        if (! Cache::add('member-qr:'.$fields['userId'].':'.$scope, true, $expires)) {
            throw new MemberQrRefused(MemberQrRefusal::Replayed);
        }

        return MemberQrScan::verified($fields['userId'], $fields['window'], $at);
    }
}
