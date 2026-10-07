<?php

declare(strict_types=1);

namespace App\Actions\Stampers\Concerns;

use App\Actions\Stampers\StamperRefused;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\ArchivedSites;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * What registering and moving a tag share (CHW-138): the tag lock, the site
 * it goes to, the new assignment, and turning a race on the same tag into a
 * refusal. Callers run inside TenantContext::bypass() and a transaction, and
 * lock in the tap path's order: the tag, then its stamper, then the site.
 */
trait AssignsTags
{
    /**
     * The location the tag goes to: the one named, or the business's only open
     * one. A pending business may set up; a suspended or archived one may not.
     */
    private function siteFor(Business $business, ?Location $location): Location
    {
        $business = Business::query()->with('organization')->findOrFail($business->id);

        if ($business->archived_at !== null || $business->organization->archived_at !== null) {
            throw new StamperRefused("{$business->name} is archived.");
        }

        if ($business->status === BusinessStatus::Suspended) {
            throw new StamperRefused("{$business->name} is suspended: lift the suspension before assigning a stamper.");
        }

        if ($location instanceof Location) {
            $location = Location::query()->findOrFail($location->id);

            if ((int) $location->business_id !== $business->id) {
                throw new StamperRefused("{$this->describe($location)} is not a location of {$business->name}.");
            }

            return $this->stillOpen($business, $location);
        }

        $open = Location::query()->where('business_id', $business->id)->open()->orderBy('id')->get();

        return match ($open->count()) {
            0 => throw new StamperRefused("{$business->name} has no open location: add one first."),
            1 => $this->stillOpen($business, $open->firstOrFail()),
            default => throw new StamperRefused("{$business->name} has {$open->count()} open locations, choose one: ".$open->map($this->describe(...))->implode(', ').'.'),
        };
    }

    /**
     * FOR NO KEY UPDATE, as ReceiveTap: register, move and taps on the tag
     * still queue, but a stamp event's foreign key check on the tag (KEY SHARE)
     * is never blocked, so a tap being applied on the old stamper cannot
     * deadlock with a move.
     */
    private function lockTag(string $uid): ?NfcTag
    {
        return NfcTag::query()->where('uid', $uid)->lock('for no key update')->first();
    }

    /**
     * The archive check under its locks (ArchivedSites): an archived location,
     * or a business or organization archived at the same moment, is a refusal,
     * not a failed insert.
     */
    private function stillOpen(Business $business, Location $location): Location
    {
        if (! ArchivedSites::isOpen($business->id, $location->id, lock: true)) {
            throw new StamperRefused("{$business->name}, {$this->describe($location)} is archived.");
        }

        return $location;
    }

    /** A new current assignment of the tag; the business pauses it later, an admin ends it. */
    private function assign(NfcTag $tag, Location $location, ?string $label): Stamper
    {
        $label = trim((string) $label);

        $stamper = (new Stamper)->forceFill([
            'business_id' => $location->business_id,
            'location_id' => $location->id,
            'nfc_tag_id' => $tag->id,
            'label' => $label === '' ? null : $label,
        ]);
        $stamper->save();

        return $stamper;
    }

    private function retired(string $uid): StamperRefused
    {
        return new StamperRefused("Tag {$uid} is retired (lost or stolen): register a new tag for its replacement.");
    }

    /**
     * Someone registering or assigning the same tag at the same moment hits the
     * uid's unique index or the one-current-assignment index: a refusal to
     * check and retry, not a server error.
     *
     * @param  Closure(): Stamper  $assignment
     */
    private function refusingRaces(string $uid, Closure $assignment): Stamper
    {
        try {
            return $assignment();
        } catch (UniqueConstraintViolationException) {
            throw new StamperRefused("Tag {$uid} was registered or assigned by someone else at the same moment: check it and try again.");
        }
    }

    private function describe(Location $location): string
    {
        return "{$location->name} (#{$location->id})";
    }
}
