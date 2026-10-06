<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Enums\TapStatus;
use App\Models\Tap;
use App\Support\Taps\TapSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;

/**
 * Moves a session's pending taps from before the claim token (CHW-142) onto
 * it, once: unclaimed ones, unowned or the signed-in customer's own (a stamp
 * that failed to apply, kept for retry). The old list is forgotten only after
 * the move, so a failed move tries again on the next request. Drop it once
 * SESSION_LIFETIME has passed since the deploy.
 */
final readonly class AdoptLegacyPendingTaps
{
    public function __construct(private TenantContext $context) {}

    public function handle(Session $session, ?int $userId): void
    {
        $ids = TapSession::legacyPendingIds($session);

        if ($ids === null) {
            return;
        }

        if ($ids !== []) {
            $hash = TapSession::hashOf(TapSession::ensureToken($session));

            $this->context->bypass(fn (): int => Tap::query()
                ->whereKey($ids)
                ->whereNull('claim_token_hash')
                ->where(fn ($owner) => $userId === null ? $owner->whereNull('user_id') : $owner->whereNull('user_id')->orWhere('user_id', $userId))
                ->whereIn('status', [TapStatus::Pending, TapStatus::Expired])
                ->update(['claim_token_hash' => $hash]));
        }

        TapSession::forgetLegacyPending($session);
    }
}
