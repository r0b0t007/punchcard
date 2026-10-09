<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Admin\RecordAudit;
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

    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    public function handle(string $uid, Business $business, ?Location $location = null, ?string $label = null): Stamper
    {
        return $this->assignUnderTagLock($uid, function (?NfcTag $tag, string $uid) use ($business, $location, $label): Stamper {
            $tag ??= $this->newTag($uid);

            if ($tag->retired_at !== null) {
                throw $this->retired($uid);
            }

            $current = $this->lockCurrentStamper($tag);

            if ($current instanceof Stamper) {
                throw new StamperRefused(__('Tag :uid is already assigned (stamper #:id): move it instead.', ['uid' => $uid, 'id' => $current->id]));
            }

            $stamper = $this->assign($tag, $this->siteFor($business, $location), $label);
            $this->recordAudit->handle('tag.registered', $tag, context: [
                'stamper_id' => $stamper->id,
                'business_id' => $stamper->business_id,
                'location_id' => $stamper->location_id,
                'key_version' => $tag->key_version,
            ]);

            return $stamper;
        });
    }

    private function newTag(string $uid): NfcTag
    {
        $tag = (new NfcTag)->forceFill(['uid' => $uid]);
        $tag->save();

        return $tag;
    }
}
