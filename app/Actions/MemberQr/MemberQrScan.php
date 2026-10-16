<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

use Carbon\CarbonImmutable;

/**
 * A member QR that counts (VerifyMemberQr, CHW-28): whose it is, the window
 * it was shown in, and when it was scanned, for the stamp or redemption it
 * proves presence for (CHW-30). Made only by VerifyMemberQr, so holding one
 * means the code was checked: a request can never pass one in.
 */
final readonly class MemberQrScan
{
    private function __construct(
        public int $userId,
        public int $window,
        public CarbonImmutable $scannedAt,
    ) {}

    /** @internal VerifyMemberQr only. */
    public static function verified(int $userId, int $window, CarbonImmutable $scannedAt): self
    {
        return new self($userId, $window, $scannedAt);
    }
}
