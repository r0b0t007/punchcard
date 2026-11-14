<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Wallet\IssueApplePass;
use App\Http\Requests\MyCardRequest;
use App\Models\User;
use App\Support\Wallet\AppleWalletConfig;
use Illuminate\Http\Response;

/**
 * Add to Apple Wallet (CHW-38, design 25a): one of the customer's cards as a
 * signed .pkpass, which Safari on iPhone and Mac opens in Wallet's add sheet.
 * Only the customer's own card (MyCardRequest: anyone else's is a 404), and a
 * 404 too while the server has no Apple certificate (the badge isn't shown
 * then). Never cached: it carries the pass's token.
 */
final class WalletPassController extends Controller
{
    public function apple(MyCardRequest $request, IssueApplePass $issueApplePass): Response
    {
        abort_unless(AppleWalletConfig::isComplete(), 404);

        $user = $request->user();
        assert($user instanceof User);

        $issued = $issueApplePass->handle($user, $request->enrollment());

        return response($issued['pkpass'], 200, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="card.pkpass"',
            'Cache-Control' => 'no-store, private',
            'Last-Modified' => $issued['pass']->changed_at->toRfc7231String(),
        ]);
    }
}
