<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Stampers\Concerns\LocksTags;
use App\Enums\StamperStatus;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;

/**
 * Records that a tag was re-provisioned with the next key version (CHW-138,
 * docs/runbooks/stamper-keys.md): only once keys 2, 3, 4 and then 0 all
 * changed on the tag, off-server. The counter stays, so every URL from
 * before still fails as a replay, and those signed with the old key as a
 * bad MAC. Its stamper must be paused first, so no tap signed with the old
 * key races the new version. $from is the version the admin re-provisioned
 * from: recording the same re-key twice would leave the tag at a version its
 * keys do not match, and every tap would fail.
 */
final readonly class RecordRekey
{
    use LocksTags;

    public function __construct(private TenantContext $context) {}

    /** @return NfcTag with its current stamper (paused, or none) loaded */
    public function handle(string $uid, int $from): NfcTag
    {
        return $this->underTagLock($uid, function (?NfcTag $tag, string $uid) use ($from): NfcTag {
            $tag = $this->requireTag($tag, $uid);

            if ($tag->retired_at !== null) {
                throw $this->retired($uid);
            }

            if ($tag->key_version !== $from) {
                throw new StamperRefused(__('Tag :uid is at key version :version, not :from: this re-key may be recorded already. Check the tag before trying again.', ['uid' => $uid, 'version' => $tag->key_version, 'from' => $from]));
            }

            if ($from >= NfcTag::LAST_KEY_VERSION) {
                throw new StamperRefused(__('Tag :uid is at the last key version (:version): replace it with a new tag.', ['uid' => $uid, 'version' => $from]));
            }

            $current = $this->lockCurrentStamper($tag);

            if ($current instanceof Stamper && $current->status === StamperStatus::Active) {
                throw new StamperRefused(__('Disable stamper #:id first, so no tap signed with the old key races the new version.', ['id' => $current->id]));
            }

            $tag->forceFill(['key_version' => $from + 1])->save();

            return $tag->setRelation('currentStamper', $current);
        });
    }
}
