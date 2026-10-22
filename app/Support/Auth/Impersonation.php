<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Session\Session;
use Illuminate\Routing\Route;

/**
 * The platform admin signed in as a business owner (CHW-144), kept in the
 * session: who is behind it, whom, at which business, until when. Read-only
 * and the business app only: the owner's pages to read (PAGES), and going
 * back to admin. Everything else is refused (WhileImpersonating).
 */
final readonly class Impersonation
{
    private const string KEY = 'impersonation';

    /**
     * The business app's pages, to read: the dashboard, cards, counter, onboarding, QR stand, staff and stampers,
     * and the page an owner who hasn't verified their email is sent to instead (its resend stays refused).
     */
    private const array PAGES = [
        'start', 'verification.notice', 'dashboard', 'cards.index', 'cards.create', 'cards.edit', 'counter.show', 'counter.scan', 'counter.scans.show',
        'onboarding.show', 'onboarding.step', 'business.qr-stand', 'staff.index', 'stampers.index',
    ];

    public function __construct(
        public int $adminId,
        public int $ownerId,
        public int $businessId,
        public string $businessName,
        public CarbonImmutable $endsAt,
    ) {}

    public static function of(Session $session): ?self
    {
        $kept = $session->get(self::KEY);

        if (! is_array($kept) || ! isset($kept['admin_id'], $kept['owner_id'], $kept['business_id'], $kept['ends_at'])) {
            return null;
        }

        return new self(
            (int) $kept['admin_id'],
            (int) $kept['owner_id'],
            (int) $kept['business_id'],
            (string) ($kept['business_name'] ?? ''),
            CarbonImmutable::createFromTimestamp((int) $kept['ends_at']),
        );
    }

    public function keep(Session $session): void
    {
        $session->put(self::KEY, [
            'admin_id' => $this->adminId,
            'owner_id' => $this->ownerId,
            'business_id' => $this->businessId,
            'business_name' => $this->businessName,
            'ends_at' => $this->endsAt->getTimestamp(),
        ]);
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::KEY);
    }

    /** The time limit, from the start: never extended. */
    public function hasEnded(CarbonInterface $now): bool
    {
        return $now->greaterThanOrEqualTo($this->endsAt);
    }

    /** Whether an impersonated request may go on: a business page read, or going back to admin. */
    public static function allows(?Route $route, string $method): bool
    {
        $name = $route?->getName();

        if ($name === 'impersonation.stop') {
            return $method === 'POST';
        }

        return in_array($method, ['GET', 'HEAD'], true) && in_array($name, self::PAGES, true);
    }
}
