<?php

declare(strict_types=1);

namespace App\Support\Taps;

use App\Enums\TapStatus;
use App\Models\Tap;
use App\Support\Tenancy\PlatformBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * The taps a browser session holds (CHW-25). Tap ids never appear in a URL, a
 * form or a redirect.
 *
 * - Pending taps (a signed-out customer's, kept until claimed, up to
 *   MAX_PENDING so a second tap does not drop an armed first one) are found
 *   in the database by the session's claim token (CHW-142): the session keeps
 *   one random token for good (GiveSessionTapToken), the tap the token's
 *   sha256. A request writing back an older session payload therefore can't
 *   lose a pending tap. Logging out ends the session, and its token with it.
 * - The tap /t/result shows, and whether it is fresh, stay in the session: a
 *   stale write there only changes which result shows, never loses a stamp.
 */
final readonly class TapSession
{
    private const string TOKEN = 'taps.token';

    private const string SHOWN = 'taps.last';

    private const string FRESH = 'taps.fresh';

    private const int MAX_PENDING = 5;

    public function __construct(private Session $session, private TenantContext $context) {}

    /** Gives the session its claim token if it has none yet. */
    public static function ensureToken(Session $session): string
    {
        $token = $session->get(self::TOKEN);

        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
            $session->put(self::TOKEN, $token);
        }

        return $token;
    }

    /** Keeps a pending tap waiting in this session; past MAX_PENDING the oldest stops waiting here. */
    public function keepPending(Tap $tap): void
    {
        $this->context->bypass(function () use ($tap): void {
            Tap::query()->whereKey($tap->id)->where('status', TapStatus::Pending)->update(['claim_token_hash' => $this->tokenHash()]);

            $kept = $this->waiting()->latest('id')->limit(self::MAX_PENDING)->pluck('id')->all();
            $this->waiting()->whereKeyNot($kept)->update(['claim_token_hash' => null]);
        });
    }

    /**
     * The pending tap ids waiting in this session, oldest first.
     *
     * @return list<int>
     */
    public function pending(): array
    {
        return $this->context->bypass(fn (): array => array_values(array_map(
            intval(...),
            $this->waiting()->oldest('id')->limit(self::MAX_PENDING)->pluck('id')->all(),
        )));
    }

    /** The newest pending tap still waiting for sign-in (not expired), read in bypass(). */
    public function newestWaiting(): ?Tap
    {
        return $this->context->bypass(fn (): ?Tap => $this->waiting()
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first());
    }

    /** Stops a tap waiting in this session (claimed, or not this customer's). */
    public function forgetPending(int $id): void
    {
        $this->context->bypass(fn (): int => Tap::query()
            ->whereKey($id)
            ->where('claim_token_hash', $this->tokenHash())
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
     * This session's pending taps (call inside bypass()).
     *
     * @return PlatformBuilder<Tap>
     */
    private function waiting(): PlatformBuilder
    {
        return Tap::query()->where('claim_token_hash', $this->tokenHash())->where('status', TapStatus::Pending);
    }

    private function tokenHash(): string
    {
        return hash('sha256', self::ensureToken($this->session));
    }
}
