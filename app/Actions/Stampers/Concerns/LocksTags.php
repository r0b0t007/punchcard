<?php

declare(strict_types=1);

namespace App\Actions\Stampers\Concerns;

use App\Actions\Stampers\StamperRefused;
use App\Models\NfcTag;
use App\Support\Nfc\TagUid;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * when none has it. Someone registering or assigning the same tag at the
     * same moment hits the uid's unique index or the one-current-assignment
     * index: a refusal to check and retry, not a server error.
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

        try {
            return $this->context->bypass(fn (): mixed => DB::transaction(fn (): mixed => $work($this->lockTag($uid), $uid)));
        } catch (UniqueConstraintViolationException) {
            throw new StamperRefused("Tag {$uid} was registered or assigned by someone else at the same moment: check it and try again.");
        }
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
}
