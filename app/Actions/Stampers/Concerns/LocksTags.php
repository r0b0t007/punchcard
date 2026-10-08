<?php

declare(strict_types=1);

namespace App\Actions\Stampers\Concerns;

use App\Actions\Stampers\StamperRefused;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Nfc\TagUid;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every platform admin Action on a tag (CHW-138) runs under the tag's lock:
 * the uid read as a reader prints it, then bypass() and one transaction,
 * the tag locked first as the tap path does. The using class has a
 * TenantContext $context.
 */
trait LocksTags
{
    /**
     * Runs $work under the tag's lock, with the normalised uid; the tag is null
     * when none has it.
     *
     * @template T
     *
     * @param  Closure(?NfcTag, string): T  $work
     * @return T
     */
    private function underTagLock(string $uid, Closure $work): mixed
    {
        try {
            $uid = TagUid::normalise($uid);
        } catch (InvalidArgumentException $invalid) {
            throw new StamperRefused($invalid->getMessage(), $invalid->getCode(), previous: $invalid);
        }

        return $this->context->bypass(fn (): mixed => DB::transaction(fn (): mixed => $work($this->lockTag($uid), $uid)));
    }

    /**
     * FOR NO KEY UPDATE, as ReceiveTap: these Actions and taps on the tag
     * queue, but a stamp event's foreign key check on the tag (KEY SHARE) is
     * never blocked, so a tap being applied on its stamper cannot deadlock
     * with them. A key version written here is the one the next tap verifies.
     */
    private function lockTag(string $uid): ?NfcTag
    {
        return NfcTag::query()->where('uid', $uid)->lock('for no key update')->first();
    }

    /** The locked tag, or a refusal naming the uid (and, for a move, saying to register it first). */
    private function requireTag(?NfcTag $tag, string $uid, bool $registerFirst = false): NfcTag
    {
        if (! $tag instanceof NfcTag) {
            throw new StamperRefused($registerFirst
                ? __('No tag :uid is registered: register it first.', ['uid' => $uid])
                : __('No tag :uid is registered.', ['uid' => $uid]));
        }

        return $tag;
    }

    /** The tag's current stamper (NfcTag::currentStamper), locked FOR UPDATE after the tag: the tap path's order. */
    private function lockCurrentStamper(NfcTag $tag): ?Stamper
    {
        return $tag->currentStamper()->lockForUpdate()->first();
    }

    private function retired(string $uid): StamperRefused
    {
        return new StamperRefused(__('Tag :uid is retired (lost or stolen): register a new tag for its replacement.', ['uid' => $uid]));
    }
}
