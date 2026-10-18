<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Admin\RecordAudit;
use App\Actions\Stampers\Concerns\LocksTags;
use App\Actions\Stampers\Concerns\SwitchesStampers;
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
 * Disabling also holds it (held_at, CHW-36), so its owner can't switch it
 * back on (B12) before the re-key; enabling, or recording the re-key
 * (RecordRekey), releases the hold.
 * Under the tag lock, so it queues with taps and the other tag Actions.
 */
final readonly class SetStamperStatus
{
    use LocksTags;
    use SwitchesStampers;

    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    public function handle(string $uid, StamperStatus $status): StamperStatusChange
    {
        return $this->underTagLock($uid, function (?NfcTag $tag, string $uid) use ($status): StamperStatusChange {
            $tag = $this->requireTag($tag, $uid);
            $current = $this->lockCurrentStamper($tag);

            if (! $current instanceof Stamper) {
                throw new StamperRefused($tag->retired_at !== null
                    ? __('Tag :uid is not assigned (it is retired).', ['uid' => $uid])
                    : __('Tag :uid is not assigned.', ['uid' => $uid]));
            }

            $previous = $current->status;
            // Its business had paused it: the hold alone is new.
            $heldNow = $status === StamperStatus::Disabled && $previous === StamperStatus::Disabled && $current->held_at === null;
            // A hold keeps the moment it was first placed.
            $this->switchStamper($current, $tag, $status, ['held_at' => $status === StamperStatus::Disabled ? ($current->held_at ?? now()) : null]);

            if ($heldNow) {
                $this->recordAudit->handle('stamper.held', $tag, context: ['stamper_id' => $current->id]);
            }

            return new StamperStatusChange($current->load(['business', 'location']), $previous, held: $heldNow);
        });
    }
}
