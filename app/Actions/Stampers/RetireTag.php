<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

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

    public function __construct(private TenantContext $context) {}

    /** @return Stamper|null the assignment it ended, with its business and location loaded */
    public function handle(string $uid): ?Stamper
    {
        return $this->underTagLock($uid, function (?NfcTag $tag, string $uid): ?Stamper {
            if (! $tag instanceof NfcTag) {
                throw new StamperRefused("No tag {$uid} is registered.");
            }

            if ($tag->retired_at !== null) {
                throw new StamperRefused("Tag {$uid} is already retired.");
            }

            $current = Stamper::query()->current()->where('nfc_tag_id', $tag->id)->lockForUpdate()->first();
            $current?->forceFill(['unassigned_at' => now()])->save();
            $tag->forceFill(['retired_at' => now()])->save();

            return $current?->load(['business', 'location']);
        });
    }
}
