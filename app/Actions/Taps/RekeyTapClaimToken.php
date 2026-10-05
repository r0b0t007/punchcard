<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Models\Tap;
use App\Support\Taps\TapSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use Throwable;

/**
 * At sign-in (Login, which fires once the session id is regenerated): a new
 * tap claim token from the new id, and the taps waiting under the old one
 * move onto it, so the customer still claims them (CHW-142). A session id
 * planted in a browser before sign-in then can't claim the taps made there
 * later. A failure to move them is reported and the old token kept, so it
 * never fails the sign-in itself.
 */
final readonly class RekeyTapClaimToken
{
    public function __construct(private TenantContext $context) {}

    public function handle(Session $session): void
    {
        $old = TapSession::ensureToken($session);
        $new = TapSession::mint($session);

        if ($old === $new) {
            return;
        }

        try {
            $this->context->bypass(fn (): int => Tap::query()
                ->waitingUnder(TapSession::hashOf($old))
                ->update(['claim_token_hash' => TapSession::hashOf($new)]));
        } catch (Throwable $failed) {
            report($failed);

            return;
        }

        TapSession::replaceToken($session, $new);
    }
}
