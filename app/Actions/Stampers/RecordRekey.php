<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Stampers\Concerns\LocksTags;
use App\Enums\StamperStatus;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Nfc\KeyDiversifier;
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

    public function handle(string $uid, int $from): NfcTag
    {
        return $this->underTagLock($uid, function (?NfcTag $tag, string $uid) use ($from): NfcTag {
            if (! $tag instanceof NfcTag) {
                throw new StamperRefused("No tag {$uid} is registered.");
            }

            if ($tag->retired_at !== null) {
                throw new StamperRefused("Tag {$uid} is retired: a lost or stolen tag is replaced, never re-keyed.");
            }

            if ($tag->key_version !== $from) {
                throw new StamperRefused("Tag {$uid} is at key version {$tag->key_version}, not {$from}: this re-key may be recorded already. Check the tag before trying again.");
            }

            if ($from >= min(NfcTag::LAST_KEY_VERSION, KeyDiversifier::MAX_KEY_VERSION)) {
                throw new StamperRefused("Tag {$uid} is at the last key version ({$from}): replace it with a new tag.");
            }

            $current = Stamper::query()->current()->where('nfc_tag_id', $tag->id)->lockForUpdate()->first();

            if ($current instanceof Stamper && $current->status === StamperStatus::Active) {
                throw new StamperRefused("Disable stamper #{$current->id} first (punchcard:stamper:disable {$uid}), so no tap signed with the old key races the new version.");
            }

            $tag->forceFill(['key_version' => $from + 1])->save();

            return $tag;
        });
    }
}
