<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoyaltyCard;
use App\Models\NfcTag;
use App\Models\StampEvent;
use App\Support\Tenancy\TenantContext;

/**
 * What nobody may do, the platform admin included (CHW-22, CLAUDE.md
 * invariants): create, change or delete a stamp (only AddStamps writes the
 * append-only ledger); create, copy or delete a tag (provisioning makes them,
 * keys and all, and they are never deleted); delete a card customers hold, or
 * change its mode and tiers, or bulk-delete cards at all. The tap log is not
 * here: its rows move, are pruned and scrubbed, and an admin may fix or erase
 * one. The admin passes every other ability in Filament (Gate::before); for
 * these the answer is an explicit no, never "no opinion": Filament allows an
 * action whose policy has no method for it.
 */
final class Invariants
{
    /** @var array<class-string, list<string>> */
    private const array NEVER = [
        NfcTag::class => ['create', 'replicate', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny'],
        StampEvent::class => ['create', 'replicate', 'update', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny'],
        LoyaltyCard::class => ['deleteAny', 'forceDeleteAny'],
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

    /**
     * Customers hold the card (LoyaltyCard::held). A list loaded
     * withExists('enrollments') answers without a query per row.
     */
    public static function isHeld(LoyaltyCard $card): bool
    {
        $loaded = $card->getAttribute('enrollments_exists');

        return $loaded !== null
            ? (bool) $loaded
            : app(TenantContext::class)->bypass(fn (): bool => LoyaltyCard::query()->whereKey($card->id)->held()->exists());
    }
}
