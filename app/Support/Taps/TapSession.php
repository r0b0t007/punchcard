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
 *   one token for good (GiveSessionTapToken), the tap the token's sha256, so
 *   a request writing back an older session payload can't lose a pending tap.
 *   A tap the scheduler expired meanwhile stays claimable, so its result says
 *   it expired. Logging out ends the session, and its token with it.
 * - The tap /t/result shows, and whether it is fresh, stay in the session: a
 *   stale write there only changes which result shows, never loses a stamp.
 */
final readonly class TapSession
{
    private const string TOKEN = 'taps.token';

    /** Before CHW-142 the session listed its pending tap ids here; moved onto the token once. */
    private const string LEGACY_PENDING = 'taps.pending';

    private const string SHOWN = 'taps.last';

    private const string FRESH = 'taps.fresh';

    private const int MAX_PENDING = 5;

    private string $tokenHash;

    public function __construct(private Session $session, private TenantContext $context)
    {
        $this->tokenHash = hash('sha256', self::ensureToken($session));
    }

    /**
     * Gives the session its claim token if it has none yet. Derived from the
     * session id (secret, like the cookie that carries it) and the app key, so
     * concurrent requests of a session without a token all mint the same one:
     * whichever saves last, the token is the same. Sign-in re-keys it
     * (rekeyAfterSignIn), so a signed-in session never holds a token derived
     * from an id that existed before: one planted in a victim's browser
     * (session fixation) can't follow the attacker into their account.
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

    /**
     * After sign-in (Login, which fires once the session id is regenerated): a
     * new token from the new id, and the taps waiting under the old one move
     * onto it, so the customer still claims them.
     */
    public static function rekeyAfterSignIn(Session $session, TenantContext $context): void
    {
        $old = $session->get(self::TOKEN);
        $new = self::mint($session);

        if (is_string($old) && $old !== '' && $old !== $new) {
            $context->bypass(fn (): int => Tap::query()
                ->where('claim_token_hash', hash('sha256', $old))
                ->whereIn('status', [TapStatus::Pending, TapStatus::Expired])
                ->update(['claim_token_hash' => hash('sha256', $new)]));
        }

        $session->put(self::TOKEN, $new);
    }

    /**
     * Moves a session's pending taps from before the claim token onto it, once
     * (unclaimed and unowned ones only). The old list is forgotten only after
     * the move, so a failed move tries again on the next request.
     */
    public function adoptLegacyPending(): void
    {
        $ids = array_values(array_filter((array) $this->session->get(self::LEGACY_PENDING, []), is_int(...)));

        if ($ids !== []) {
            $this->context->bypass(fn (): int => Tap::query()
                ->whereKey($ids)
                ->whereNull('claim_token_hash')
                ->whereNull('user_id')
                ->whereIn('status', [TapStatus::Pending, TapStatus::Expired])
                ->update(['claim_token_hash' => $this->tokenHash]));
        }

        $this->session->forget(self::LEGACY_PENDING);
    }

    private static function mint(Session $session): string
    {
        return hash_hmac('sha256', 'tap-claim-token|'.$session->getId(), (string) config('app.key'));
    }

    /** Keeps a pending tap waiting in this session; past MAX_PENDING the oldest stops waiting here. */
    public function keepPending(Tap $tap): void
    {
        $this->context->bypass(function () use ($tap): void {
            Tap::query()->whereKey($tap->id)->where('status', TapStatus::Pending)->update(['claim_token_hash' => $this->tokenHash]);

            $this->claimable()
                ->whereNotIn('id', $this->claimable()->select('id')->orderByDesc('id')->limit(self::MAX_PENDING))
                ->update(['claim_token_hash' => null]);
        });
    }

    /**
     * The taps waiting in this session, oldest first: pending ones, and any
     * the scheduler expired before they were claimed.
     *
     * @return Collection<int, Tap>
     */
    public function pendingTaps(): Collection
    {
        return $this->context->bypass(fn (): Collection => $this->claimable()->oldest('id')->limit(self::MAX_PENDING)->get());
    }

    /**
     * The ids of the taps waiting in this session, oldest first.
     *
     * @return list<int>
     */
    public function pending(): array
    {
        return array_values($this->pendingTaps()->modelKeys());
    }

    /** The newest pending tap still waiting for sign-in (not expired), read in bypass(). */
    public function newestWaiting(): ?Tap
    {
        return $this->context->bypass(fn (): ?Tap => $this->claimable()
            ->where('status', TapStatus::Pending)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first());
    }

    /** Stops a tap waiting in this session (claimed, expired and told, or not this customer's). */
    public function forgetPending(int $id): void
    {
        $this->context->bypass(fn (): int => Tap::query()
            ->whereKey($id)
            ->where('claim_token_hash', $this->tokenHash)
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
     * This session's claimable taps (call inside bypass()).
     *
     * @return PlatformBuilder<Tap>
     */
    private function claimable(): PlatformBuilder
    {
        return Tap::query()
            ->where('claim_token_hash', $this->tokenHash)
            ->whereIn('status', [TapStatus::Pending, TapStatus::Expired]);
    }
}
