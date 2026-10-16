<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use App\Support\QrCode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * C10, "My QR" (CHW-28): for a phone without NFC, the customer's member QR,
 * which staff scan (CHW-30). The code of the current window and the
 * seconds it has left: the page asks for the next one as it runs out. The
 * token goes out only as the QR's image. Never cached (NeverCache).
 */
final class MemberQrController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $issued = MemberQrToken::issue($user);

        return Inertia::render('app/qr', [
            'qrCode' => QrCode::dataUrl($issued['token']),
            'secondsLeft' => $issued['secondsLeft'],
            'windowSeconds' => MemberQrToken::windowSeconds(),
            'firstName' => $user->firstName(),
        ]);
    }
}
