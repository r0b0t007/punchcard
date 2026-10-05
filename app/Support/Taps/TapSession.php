<?php

declare(strict_types=1);

namespace App\Support\Taps;

use App\Enums\TapStatus;
use App\Models\Tap;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;

/**
 * The taps a browser session holds (CHW-25). Tap ids live here only, never in
 * a URL, a form or a redirect: the pending taps a signed-out customer made
 * (kept until claimed, up to MAX_PENDING, so a second tap does not drop an
 * armed first one) and the tap /t/result shows.
 */
final readonly class TapSession
{
    private const string PENDING = 'taps.pending';

    private const string SHOWN = 'taps.last';

    private const string FRESH = 'taps.fresh';

    private const int MAX_PENDING = 5;

    public function __construct(private Session $session, private TenantContext $context) {}

    public function keepPending(Tap $tap): void
    {
        $pending = array_filter($this->pending(), fn (int $id): bool => $id !== $tap->id);

        $this->session->put(self::PENDING, array_slice([...$pending, $tap->id], -self::MAX_PENDING));
    }

    /**
     * The pending tap ids, oldest first.
     *
     * @return list<int>
     */
    public function pending(): array
    {
        $ids = array_values(array_filter((array) $this->session->get(self::PENDING, []), is_int(...)));
        sort($ids);

        return $ids;
    }

    /** The newest pending tap still waiting for sign-in (not expired), read in bypass(). */
    public function newestWaiting(): ?Tap
    {
        $ids = $this->pending();

        return $ids === [] ? null : $this->context->bypass(fn (): ?Tap => Tap::query()
            ->whereKey($ids)
            ->where('status', TapStatus::Pending)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first());
    }

    public function forgetPending(int $id): void
    {
        $this->session->put(self::PENDING, array_values(array_filter($this->pending(), fn (int $pending): bool => $pending !== $id)));
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
}
