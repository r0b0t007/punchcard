<?php

declare(strict_types=1);

namespace App\Support\Referrals;

use App\Models\Business;
use Illuminate\Contracts\Session\Session;

/**
 * A friend's invitation, held by the browser session (CHW-47): the referral
 * code of a join page's link (/j/{slug}?ref=CODE), for that business only
 * (by its id: a slug can change).
 * It survives signing up and signing in (the session id changes, its data
 * stays), so the card added on the join page, or by a first tap there, is
 * the one it names. A new link replaces it. Only ever a code: who referred,
 * and whether it counts, is ReferrerFor's to say when the card is added.
 */
final readonly class PendingReferral
{
    private const string KEY = 'referral';

    /** A code as EnrollCustomer makes them: 8 of its letters and digits. */
    private const string CODE = '/^[A-HJ-NP-Z2-9]{8}$/D';

    private function __construct(public int $businessId, public string $code) {}

    /** Keeps a join page's code; one that could never be a code is left out. */
    public static function remember(Session $session, Business $business, mixed $code): void
    {
        $code = is_string($code) ? strtoupper(trim($code)) : '';

        if (preg_match(self::CODE, $code) === 1) {
            $session->put(self::KEY, ['business' => $business->id, 'code' => $code]);
        }
    }

    public static function from(Session $session): ?self
    {
        $held = $session->get(self::KEY);

        return is_array($held) && is_int($held['business'] ?? null) && is_string($held['code'] ?? null)
            ? new self($held['business'], $held['code'])
            : null;
    }

    /** The code, when it was given for this business. */
    public function for(Business $business): ?string
    {
        return $this->businessId === $business->id ? $this->code : null;
    }
}
