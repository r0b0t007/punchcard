<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Stampers\Concerns\AssignsTags;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;

/**
 * The platform admin registers an NFC tag and assigns it to a location of a
 * business (CHW-138, A2): a new tag starts at key version 1 and counter 0; a
 * known tag that is free again keeps both, so its old URLs stay replays. A
 * retired tag or one already assigned is refused (moving is MoveStamper).
 * Keys are derived from the uid and key version, never handled here
 * (docs/runbooks/stamper-keys.md).
 */
final readonly class RegisterStamper
{
    use AssignsTags;

    public function __construct(private TenantContext $context) {}

    public function handle(string $uid, Business $business, ?Location $location = null, ?string $label = null): Stamper
    {
        return $this->underTagLock($uid, function (?NfcTag $tag, string $uid) use ($business, $location, $label): Stamper {
            $tag ??= $this->newTag($uid);

            if ($tag->retired_at !== null) {
                throw $this->retired($uid);
            }

            $current = Stamper::query()->current()->where('nfc_tag_id', $tag->id)->first();

            if ($current instanceof Stamper) {
                throw new StamperRefused("Tag {$uid} is already assigned (stamper #{$current->id}): move it instead.");
            }

            return $this->assign($tag, $this->siteFor($business, $location), $label);
        });
    }

    private function newTag(string $uid): NfcTag
    {
        $tag = (new NfcTag)->forceFill(['uid' => $uid]);
        $tag->save();

        return $tag;
    }
}
