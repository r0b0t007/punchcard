<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use App\Support\QrCode;

/**
 * C10, "My QR" (CHW-28): the customer's member QR now, as the image staff
 * scan (the token goes out only as that), the seconds it has left, and when
 * it was issued (ms), so a page restored from history knows its code is old.
 */
final readonly class DescribeMemberQr
{
    /**
     * @return array{qrCode: string, secondsLeft: int, shortCode: string, windowSeconds: int, issuedAt: int, firstName: string|null}
     */
    public function handle(User $user): array
    {
        $issued = MemberQrToken::issue($user);

        return [
            'qrCode' => QrCode::dataUrl($issued['token']),
            'secondsLeft' => $issued['secondsLeft'],
            // To type when a camera can't read the QR (CHW-30): new with every code.
            'shortCode' => MemberQrToken::shortCode($user),
            'windowSeconds' => MemberQrToken::windowSeconds(),
            'issuedAt' => now()->getTimestampMs(),
            'firstName' => $user->firstName(),
        ];
    }
}
