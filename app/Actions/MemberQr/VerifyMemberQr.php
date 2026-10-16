<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

use SensitiveParameter;

/**
 * Whether a scanned member QR counts (CHW-28), for staff scanning (CHW-30):
 * the scan it proves (MemberQrScan::fromToken, which holds the rules: a
 * real, fresh token of an existing customer, used once per customer and
 * scope while a code of theirs can count), or MemberQrRefused, saying why.
 * A MemberQrScan only ever comes from a checked token.
 */
final readonly class VerifyMemberQr
{
    /** @throws MemberQrRefused */
    public function handle(#[SensitiveParameter] string $token, string $scope): MemberQrScan
    {
        return MemberQrScan::fromToken($token, $scope);
    }
}
