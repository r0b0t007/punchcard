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
use App\Support\Nfc\TagUid;
use App\Support\Tenancy\ArchivedSites;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;

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
            throw StamperRefused::siteGone();
        }

        if ($location instanceof Location && (int) $location->business_id !== $business->id) {
            throw new StamperRefused(__(':site is not a location of :business.', ['site' => SiteName::of($location), 'business' => $business->name]));
        }

        $location ??= $this->onlyOpenLocation($business);

        if (! ArchivedSites::isOpen($business->id, $location->id, lock: true, counter: true)) {
            throw new StamperRefused($this->whyClosed($business, $location) ?? __(':business changed at the same moment: check it and try again.', ['business' => $business->name]));
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
            throw new StamperRefused(__('A stamper label is at most :max characters.', ['max' => self::LABEL_MAX]));
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

    /**
     * underTagLock for the Actions that insert: someone registering or
     * assigning the same tag at the same moment hits the uid's unique index or
     * the one-current-assignment index, a refusal to check and retry rather
     * than a server error.
     *
     * @param  Closure(?NfcTag, string): Stamper  $work
     */
    private function assignUnderTagLock(string $uid, Closure $work): Stamper
    {
        try {
            return $this->underTagLock($uid, $work);
        } catch (UniqueConstraintViolationException) {
            throw new StamperRefused(__('Tag :uid was registered or assigned by someone else at the same moment: check it and try again.', ['uid' => TagUid::normalise($uid)]));
        }
    }

    private function onlyOpenLocation(Business $business): Location
    {
        $open = Location::query()->where('business_id', $business->id)->open()->orderBy('id')->get();

        return match ($open->count()) {
            0 => throw new StamperRefused($this->whyClosed($business, null) ?? __(':business has no open location: add one first.', ['business' => $business->name])),
            1 => $open->firstOrFail(),
            default => throw new StamperRefused(__(':business has :count open locations, choose one: :sites.', ['business' => $business->name, 'count' => $open->count(), 'sites' => $open->map(SiteName::of(...))->implode(', ')])),
        };
    }

    /** Why the site takes no stamper, read fresh; null when nothing is closed. */
    private function whyClosed(Business $business, ?Location $location): ?string
    {
        $business = Business::query()->with('organization')->find($business->id);

        $reason = match (true) {
            ! $business instanceof Business => __('The business no longer exists: check it and try again.'),
            $business->archived_at !== null, $business->organization->archived_at !== null => __(':business is archived.', ['business' => $business->name]),
            $business->status === BusinessStatus::Suspended => __(':business is suspended: lift the suspension before assigning a stamper.', ['business' => $business->name]),
            $location instanceof Location && Location::query()->whereKey($location->id)->value('archived_at') !== null => __(':business, :site is archived.', ['business' => $business->name, 'site' => SiteName::of($location)]),
            default => null,
        };

        return is_string($reason) ? $reason : null;
    }
}
