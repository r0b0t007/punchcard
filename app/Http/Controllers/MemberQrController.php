<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MemberQr\DescribeMemberQr;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * C10, "My QR" (CHW-28): for a phone without NFC, the customer's member QR,
 * which staff scan (CHW-30), now (DescribeMemberQr): the page asks for the
 * next one as it runs out. Never cached (NeverCache).
 */
final class MemberQrController extends Controller
{
    public function show(Request $request, DescribeMemberQr $describeMemberQr): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('app/qr', $describeMemberQr->handle($user));
    }
}
