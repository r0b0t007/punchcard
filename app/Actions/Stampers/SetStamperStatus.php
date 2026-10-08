<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Stampers\Concerns\LocksTags;
use App\Enums\StamperStatus;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;

/**
 * The platform admin disables or re-enables a tag's current stamper (CHW-138,
 * docs/runbooks/stamper-keys.md): around a re-key, so no tap signed with the
 * old key races the new version. A disabled stamper refuses every tap. Under
 * the tag lock, so it queues with taps and the other tag Actions.
 */
final readonly class SetStamperStatus
{
    use LocksTags;

    public function __construct(private TenantContext $context) {}

    /** @return Stamper the tag's current stamper, with its business and location loaded */
    public function handle(string $uid, StamperStatus $status): Stamper
    {
        return $this->underTagLock($uid, function (?NfcTag $tag, string $uid) use ($status): Stamper {
            if (! $tag instanceof NfcTag) {
                throw new StamperRefused("No tag {$uid} is registered.");
            }

            $current = Stamper::query()->current()->where('nfc_tag_id', $tag->id)->lockForUpdate()->first();

            if (! $current instanceof Stamper) {
                throw new StamperRefused("Tag {$uid} is not assigned".($tag->retired_at !== null ? ' (it is retired).' : '.'));
            }

            $current->forceFill(['status' => $status])->save();

            return $current->load(['business', 'location']);
        });
    }
}
