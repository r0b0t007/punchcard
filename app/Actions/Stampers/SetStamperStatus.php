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
 * old key races the new version. A disabled stamper refuses every tap, and
 * disabling clears its arming, so stamps armed before never land on the first
 * tap after it is enabled again. It reports the status it found: a stamper
 * the business had already paused should stay paused after the re-key.
 * Under the tag lock, so it queues with taps and the other tag Actions.
 */
final readonly class SetStamperStatus
{
    use LocksTags;

    public function __construct(private TenantContext $context) {}

    public function handle(string $uid, StamperStatus $status): StamperStatusChange
    {
        return $this->underTagLock($uid, function (?NfcTag $tag, string $uid) use ($status): StamperStatusChange {
            $tag = $this->requireTag($tag, $uid);
            $current = $this->lockCurrentStamper($tag);

            if (! $current instanceof Stamper) {
                throw new StamperRefused("Tag {$uid} is not assigned".($tag->retired_at !== null ? ' (it is retired).' : '.'));
            }

            $previous = $current->status;
            $current->forceFill(['status' => $status] + ($status === StamperStatus::Disabled ? ['armed_qty' => null, 'armed_until' => null] : []))->save();

            return new StamperStatusChange($current->load(['business', 'location']), $previous);
        });
    }
}
