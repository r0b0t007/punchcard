<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

use App\Support\MemberQr\MemberQrToken;
use SensitiveParameter;

/**
 * Whether a scanned member QR, or the short code typed in its place, counts
 * (CHW-28, CHW-30): the scan it proves (MemberQrScan::fromToken or fromCode,
 * which hold the rules: a real, fresh code of an existing customer, used
 * once per customer and scope while a code of theirs can count), or
 * MemberQrRefused, saying why. A token starts with its version (PC1.);
 * anything else is read as a short code. A MemberQrScan only ever comes
 * from a checked code.
 */
final readonly class VerifyMemberQr
{
    /** @throws MemberQrRefused */
    public function handle(#[SensitiveParameter] string $scanned, string $scope): MemberQrScan
    {
        $scanned = trim($scanned);

        return str_starts_with($scanned, MemberQrToken::VERSION.'.')
            ? MemberQrScan::fromToken($scanned, $scope)
            : MemberQrScan::fromCode($scanned, $scope);
    }
}
