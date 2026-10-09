<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Admin\RecordAudit;
use App\Actions\Stampers\Concerns\LocksTags;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;

/**
 * The platform admin retires a lost or stolen tag (CHW-138,
 * docs/runbooks/stamper-keys.md): one-way, so it is never assigned again,
 * and its current assignment ends with it. Someone holding it can still tap,
 * and each tap is refused as a retired tag. A new tag replaces it; a
 * retired tag is never re-keyed.
 */
final readonly class RetireTag
{
    use LocksTags;

    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    /** @return Stamper|null the assignment it ended, with its business and location loaded */
    public function handle(string $uid): ?Stamper
    {
        return $this->underTagLock($uid, function (?NfcTag $tag, string $uid): ?Stamper {
            $tag = $this->requireTag($tag, $uid);

            if ($tag->retired_at !== null) {
                throw new StamperRefused(__('Tag :uid is already retired.', ['uid' => $uid]));
            }

            $current = $this->lockCurrentStamper($tag);
            $now = now();
            $current?->forceFill(['unassigned_at' => $now])->save();
            $tag->forceFill(['retired_at' => $now])->save();
            $this->recordAudit->handle('tag.retired', $tag, context: $current instanceof Stamper ? ['stamper_id' => $current->id] : []);

            return $current?->load(['business', 'location']);
        });
    }
}
