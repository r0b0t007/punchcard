<?php

declare(strict_types=1);

namespace App\Actions\Stampers\Concerns;

use App\Actions\Stampers\SiteName;
use App\Actions\Stampers\StamperRefused;
use App\Enums\BusinessStatus;
use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\ArchivedSites;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What registering and moving a tag share (CHW-138): the site it goes to and
 * the new assignment, under the tag's lock (LocksTags), locking in the tap
 * path's order: the tag, then its stamper, then the site.
 */
trait AssignsTags
{
    use LocksTags;

    private const int LABEL_MAX = 255;

    /**
     * The location the tag goes to: the one named, or the business's only open
     * one. A pending business may set up; a suspended or archived one, or an
     * archived location, may not. That is checked once, under the archive
     * locks (ArchivedSites), so a suspension or archive at the same moment
     * waits for this write or is seen by it.
     */
    private function siteFor(Business $business, ?Location $location): Location
    {
        try {
            $business = Business::query()->findOrFail($business->id);
            $location = $location instanceof Location ? Location::query()->findOrFail($location->id) : null;
        } catch (ModelNotFoundException) {
            throw new StamperRefused('The business or location no longer exists: check it and try again.');
        }

        if ($location instanceof Location && (int) $location->business_id !== $business->id) {
            throw new StamperRefused(SiteName::of($location)." is not a location of {$business->name}.");
        }

        $location ??= $this->onlyOpenLocation($business);

        if (! ArchivedSites::isOpen($business->id, $location->id, lock: true, counter: true)) {
            throw new StamperRefused($this->whyClosed($business, $location) ?? "{$business->name} changed at the same moment: check it and try again.");
        }

        return $location;
    }

    /**
     * A new current assignment of the tag; the business pauses or arms it
     * later, an admin ends it. It comes back with its tag and location loaded.
     */
    private function assign(NfcTag $tag, Location $location, ?string $label, StamperStatus $status = StamperStatus::Active): Stamper
    {
        $label = trim((string) $label);

        if (mb_strlen($label) > self::LABEL_MAX) {
            throw new StamperRefused('A stamper label is at most '.self::LABEL_MAX.' characters.');
        }

        $stamper = (new Stamper)->forceFill([
            'business_id' => $location->business_id,
            'location_id' => $location->id,
            'nfc_tag_id' => $tag->id,
            'label' => $label === '' ? null : $label,
            'status' => $status,
        ]);
        $stamper->save();

        return $stamper->setRelation('tag', $tag)->setRelation('location', $location);
    }

    private function retired(string $uid): StamperRefused
    {
        return new StamperRefused("Tag {$uid} is retired (lost or stolen): register a new tag for its replacement.");
    }

    private function onlyOpenLocation(Business $business): Location
    {
        $open = Location::query()->where('business_id', $business->id)->open()->orderBy('id')->get();

        return match ($open->count()) {
            0 => throw new StamperRefused($this->whyClosed($business, null) ?? "{$business->name} has no open location: add one first."),
            1 => $open->firstOrFail(),
            default => throw new StamperRefused("{$business->name} has {$open->count()} open locations, choose one: ".$open->map(SiteName::of(...))->implode(', ').'.'),
        };
    }

    /** Why the site takes no stamper, read fresh; null when nothing is closed. */
    private function whyClosed(Business $business, ?Location $location): ?string
    {
        $business = Business::query()->with('organization')->find($business->id);

        return match (true) {
            ! $business instanceof Business => 'The business no longer exists: check it and try again.',
            $business->archived_at !== null, $business->organization->archived_at !== null => "{$business->name} is archived.",
            $business->status === BusinessStatus::Suspended => "{$business->name} is suspended: lift the suspension before assigning a stamper.",
            $location instanceof Location && Location::query()->whereKey($location->id)->value('archived_at') !== null => "{$business->name}, ".SiteName::of($location).' is archived.',
            default => null,
        };
    }
}
