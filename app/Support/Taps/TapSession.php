<?php

declare(strict_types=1);

namespace App\Support\Taps;

use App\Enums\TapStatus;
use App\Models\Tap;
use App\Support\Tenancy\PlatformBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

/**
 * The taps a browser session holds (CHW-25). Tap ids never appear in a URL, a
 * form or a redirect.
 *
 * - Pending taps (a signed-out customer's, kept until claimed, up to
 *   MAX_PENDING so a second tap does not drop an armed first one) are found
 *   in the database by the session's claim token (CHW-142): the session keeps
 *   one token (GiveSessionTapToken), the tap the token's sha256, written by
 *   ReceiveTap with the tap itself, so a request writing back an older
 *   session payload can't lose a pending tap (Tap::waitingUnder). Sign-in
 *   re-keys the token (RekeyTapClaimToken); logging out ends the session.
 * - The tap /t/result shows, and whether it is fresh, stay in the session: a
 *   stale write there only changes which result shows, never loses a stamp.
 */
final readonly class TapSession
{
    private const string TOKEN = 'taps.token';

    /** Before CHW-142 the session listed its pending tap ids here (AdoptLegacyPendingTaps). */
    private const string LEGACY_PENDING = 'taps.pending';

    private const string SHOWN = 'taps.last';

    private const string FRESH = 'taps.fresh';

    private const int MAX_PENDING = 5;

    public function __construct(private Session $session, private TenantContext $context) {}

    /**
     * Gives the session its claim token if it has none yet. Derived from the
     * session id (secret, like the cookie that carries it) and the app key, so
     * concurrent requests of a session without a token all mint the same one:
     * whichever saves last, the token is the same. Sign-in re-keys it from the
     * regenerated id (RekeyTapClaimToken), so a session id planted before
     * sign-in can't claim the taps made later, unless that re-key failed and
     * was reported (the old token is kept then, so the sign-in still works).
     */
    public static function ensureToken(Session $session): string
    {
        $token = $session->get(self::TOKEN);

        if (! is_string($token) || $token === '') {
            $token = self::mint($session);
            $session->put(self::TOKEN, $token);
        }

        return $token;
    }

    /** The session's claim token as it would be minted from its id now. */
    public static function mint(Session $session): string
    {
        return hash_hmac('sha256', 'tap-claim-token|'.$session->getId(), (string) config('app.key'));
    }

    /** Replaces the session's claim token (RekeyTapClaimToken). */
    public static function replaceToken(Session $session, string $token): void
    {
        $session->put(self::TOKEN, $token);
    }

    /** What a tap stores of a claim token: its sha256, never the token. */
    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The tap ids the session listed before the claim token, if it still has that list.
     *
     * @return list<int>|null
     */
    public static function legacyPendingIds(Session $session): ?array
    {
        return $session->has(self::LEGACY_PENDING)
            ? array_values(array_filter((array) $session->get(self::LEGACY_PENDING), is_int(...)))
            : null;
    }

    public static function forgetLegacyPending(Session $session): void
    {
        $session->forget(self::LEGACY_PENDING);
    }

    /** The hash ReceiveTap stores on a tap made in this session, from its token as it is now. */
    public function claimTokenHash(): string
    {
        return self::hashOf(self::ensureToken($this->session));
    }

    /**
     * Keeps at most MAX_PENDING taps waiting in this session, the newest
     * (ReceiveTap already linked the new one, with the tap itself).
     */
    public function trimPending(): void
    {
        $this->context->bypass(fn (): int => $this->waiting()
            // A subquery on the same table with LIMIT: valid on PostgreSQL and SQLite, not MySQL.
            ->whereNotIn('id', $this->waiting()->select('id')->orderByDesc('id')->limit(self::MAX_PENDING))
            ->update(['claim_token_hash' => null]));
    }

    /**
     * The taps waiting in this session, oldest first. All of them: a move-over
     * or a sign-in re-key can leave more than MAX_PENDING, and a claim must not
     * skip the newest.
     *
     * @return Collection<int, Tap>
     */
    public function pendingTaps(): Collection
    {
        return $this->context->bypass(fn (): Collection => $this->waiting()->oldest('id')->get());
    }

    /** Whether any tap still waits in this session. */
    public function hasPending(): bool
    {
        return $this->context->bypass(fn (): bool => $this->waiting()->exists());
    }

    /** The newest pending tap still waiting for sign-in (not expired), read in bypass(). */
    public function newestWaiting(): ?Tap
    {
        return $this->context->bypass(fn (): ?Tap => $this->waiting()
            ->where('status', TapStatus::Pending)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first());
    }

    /** Stops a tap waiting in this session (expired and told, or not this customer's). */
    public function forgetPending(int $id): void
    {
        $this->context->bypass(fn (): int => Tap::query()
            ->whereKey($id)
            ->where('claim_token_hash', $this->claimTokenHash())
            ->update(['claim_token_hash' => null]));
    }

    /** Makes the tap the result; a tap not shown before is fresh until its result page is first seen. */
    public function show(Tap $tap): void
    {
        if ($this->session->get(self::SHOWN) !== $tap->id) {
            $this->session->put(self::FRESH, $tap->id);
        }

        $this->session->put(self::SHOWN, $tap->id);
    }

    /** Whether this is the first look at the tap's result (its stamp lands only then); asking uses it up. */
    public function firstLookAt(Tap $tap): bool
    {
        return $this->session->pull(self::FRESH) === $tap->id;
    }

    /** The tap /t/result shows, read in bypass(): taps are platform data. */
    public function shown(): ?Tap
    {
        $id = $this->session->get(self::SHOWN);

        return is_int($id) ? $this->context->bypass(fn (): ?Tap => Tap::query()->find($id)) : null;
    }

    /**
     * This session's waiting taps, by its token as it is now (call inside bypass()).
     *
     * @return PlatformBuilder<Tap>
     */
    private function waiting(): PlatformBuilder
    {
        return Tap::query()->waitingUnder($this->claimTokenHash());
    }
}
