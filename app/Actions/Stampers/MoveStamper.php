<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Stampers\Concerns\AssignsTags;
use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Nfc\TagUid;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The platform admin moves a tag to another location or business (CHW-138,
 * docs/runbooks/stamper-keys.md): its assignment ends (one-way) and a new one
 * starts, in one transaction. The tag keeps its counter and keys, so URLs
 * from the old site stay replays and the old holder can never take it back.
 * The stamper keeps its label unless a new one is given. Within the business
 * it keeps its status (a paused stamper stays paused); at another business
 * it starts active, the pause having been the old business's choice.
 */
final readonly class MoveStamper
{
    use AssignsTags;

    public function __construct(private TenantContext $context) {}

    public function handle(string $uid, Business $business, ?Location $location = null, ?string $label = null): Stamper
    {
        $uid = TagUid::normalise($uid);

        return $this->refusingRaces($uid, fn (): Stamper => $this->context->bypass(fn (): Stamper => DB::transaction(function () use ($uid, $business, $location, $label): Stamper {
            $tag = $this->lockTag($uid);

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

            return $this->assign(
                $tag,
                $site,
                $label ?? $current->label,
                (int) $current->business_id === $site->business_id ? $current->status : StamperStatus::Active,
            );
        })));
    }
}
