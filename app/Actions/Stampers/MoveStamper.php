<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Stampers\Concerns\AssignsTags;
use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;

/**
 * The platform admin moves a tag to another location or business (CHW-138,
 * docs/runbooks/stamper-keys.md): its assignment ends (one-way) and a new one
 * starts, in one transaction. The tag keeps its counter and keys, so URLs
 * from the old site stay replays and the old holder can never take it back.
 *
 * Within the business the stamper keeps its label (unless a new one is given)
 * and its status, so a paused stamper stays paused. At another business it
 * starts active and unlabelled: the label and the pause were the old
 * business's, and its site data never reaches another tenant. An arming
 * never follows the tag: staff arm a stamper for the customer standing at
 * that counter.
 */
final readonly class MoveStamper
{
    use AssignsTags;

    public function __construct(private TenantContext $context) {}

    public function handle(string $uid, Business $business, ?Location $location = null, ?string $label = null): Stamper
    {
        return $this->underTagLock($uid, function (?NfcTag $tag, string $uid) use ($business, $location, $label): Stamper {
            if (! $tag instanceof NfcTag) {
                throw new StamperRefused("No tag {$uid} is registered: register it first.");
            }

            if ($tag->retired_at !== null) {
                throw $this->retired($uid);
            }

            $current = Stamper::query()->current()->where('nfc_tag_id', $tag->id)->lockForUpdate()->first();

            if (! $current instanceof Stamper) {
                throw new StamperRefused("Tag {$uid} is not assigned: register it instead.");
            }

            $site = $this->siteFor($business, $location);

            if ((int) $current->location_id === $site->id) {
                throw new StamperRefused("Tag {$uid} is already at ".SiteName::of($site).'.');
            }

            $current->forceFill(['unassigned_at' => now()])->save();
            $sameBusiness = (int) $current->business_id === (int) $site->business_id;

            return $sameBusiness
                ? $this->assign($tag, $site, $label ?? $current->label, $current->status)
                : $this->assign($tag, $site, $label, StamperStatus::Active);
        });
    }
}
