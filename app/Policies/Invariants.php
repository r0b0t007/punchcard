<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\NfcTag;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Support\Tenancy\TenantContext;

/**
 * What nobody may do, the platform admin included (CHW-22, CLAUDE.md
 * invariants): delete a tag; change or delete a stamp or a tap (the ledger
 * and the tap log are append-only); delete a card customers hold, or change
 * its mode and tiers. The admin passes every other ability in Filament
 * (Gate::before); for these the answer is an explicit no, never "no opinion":
 * Filament allows an action whose policy has no method for it.
 */
final class Invariants
{
    /** @var array<class-string, list<string>> */
    private const array NEVER = [
        NfcTag::class => ['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny'],
        StampEvent::class => ['update', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate'],
        Tap::class => ['update', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate'],
    ];

    /**
     * Whether the ability breaks an invariant for this subject.
     *
     * @param  array<int, mixed>  $arguments  the Gate arguments: a model or its class first
     */
    public static function forbid(string $ability, array $arguments): bool
    {
        $subject = $arguments[0] ?? null;

        foreach (self::NEVER as $class => $abilities) {
            if (($subject instanceof $class || (is_string($subject) && is_a($subject, $class, true))) && in_array($ability, $abilities, true)) {
                return true;
            }
        }

        return $subject instanceof LoyaltyCard && in_array($ability, ['delete', 'changeMode'], true) && self::isHeld($subject);
    }

    /** Customers hold the card: its mode and tiers are fixed, and it is switched off, never deleted. */
    public static function isHeld(LoyaltyCard $card): bool
    {
        return app(TenantContext::class)->bypass(fn (): bool => CardEnrollment::query()->where('card_id', $card->id)->exists());
    }
}
